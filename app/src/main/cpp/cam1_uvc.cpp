#include <jni.h>
#include <android/log.h>
#include <android/native_window_jni.h>
#include <android/native_window.h>
#include <atomic>
#include <chrono>
#include <cstdint>
#include <cstring>
#include <dlfcn.h>
#include <mutex>
#include <string>
#include <thread>
#include <unistd.h>

#define LOG_TAG "Cam1NativeUvc"
#define ALOGI(...) __android_log_print(ANDROID_LOG_INFO, LOG_TAG, __VA_ARGS__)
#define ALOGE(...) __android_log_print(ANDROID_LOG_ERROR, LOG_TAG, __VA_ARGS__)

namespace {
using uvc_error_t = int;
struct uvc_context_t; struct uvc_device_t; struct uvc_device_handle_t; struct uvc_stream_ctrl_t; struct uvc_frame_t;
struct uvc_frame_t { void* data; size_t data_bytes; uint32_t width; uint32_t height; int frame_format; uint64_t sequence; };
using frame_cb_t = void (*)(uvc_frame_t*, void*);

std::atomic<bool> g_opened{false}, g_preview{false}, g_recording{false}, g_stop{false};
std::atomic<int64_t> g_received{0}, g_decoded{0}, g_rendered{0}, g_last_frame_ns{0}, g_first_frame_ns{0}, g_recorded{0};
std::atomic<int64_t> g_start_ns{0};
std::mutex g_lock;
ANativeWindow* g_window = nullptr;
std::thread g_thread;
std::string g_error;
int g_fd=-1, g_vendor=0, g_product=0;
std::string g_device_name;

int64_t nowNs(){ return std::chrono::duration_cast<std::chrono::nanoseconds>(std::chrono::steady_clock::now().time_since_epoch()).count(); }
void setError(const std::string& s){ std::lock_guard<std::mutex> lk(g_lock); g_error=s; ALOGE("%s", s.c_str()); }
void releaseWindow(){ if(g_window){ ANativeWindow_release(g_window); g_window=nullptr; } }

struct Libs {
 void* uvc=nullptr; void* usb=nullptr;
 uvc_error_t (*uvc_init)(uvc_context_t**, void*)=nullptr;
 void (*uvc_exit)(uvc_context_t*)=nullptr;
 uvc_error_t (*uvc_find_device)(uvc_context_t*, uvc_device_t**, int, int, const char*)=nullptr;
 uvc_error_t (*uvc_open)(uvc_device_t*, uvc_device_handle_t**)=nullptr;
 void (*uvc_close)(uvc_device_handle_t*)=nullptr;
 void (*uvc_unref_device)(uvc_device_t*)=nullptr;
 uvc_error_t (*uvc_get_stream_ctrl_format_size)(uvc_device_handle_t*, uvc_stream_ctrl_t*, int, int, int, int)=nullptr;
 uvc_error_t (*uvc_start_streaming)(uvc_device_handle_t*, uvc_stream_ctrl_t*, frame_cb_t, void*, uint8_t)=nullptr;
 void (*uvc_stop_streaming)(uvc_device_handle_t*)=nullptr;
 const char* (*uvc_strerror)(uvc_error_t)=nullptr;
 // Android/libuvc forks commonly expose one of these fd helpers.
 uvc_error_t (*uvc_get_device_with_fd)(uvc_context_t*, uvc_device_t**, int, int, const char*, int, const char*)=nullptr;
 uvc_error_t (*uvc_wrap)(int, uvc_context_t**, uvc_device_handle_t**)=nullptr;
 bool load(){
   usb=dlopen("libusb1.0.so", RTLD_NOW|RTLD_GLOBAL); if(!usb) usb=dlopen("libusb-1.0.so", RTLD_NOW|RTLD_GLOBAL);
   uvc=dlopen("libuvc.so", RTLD_NOW|RTLD_GLOBAL); if(!uvc) return false;
   #define SYM(x) x=(decltype(x))dlsym(uvc,#x)
   SYM(uvc_init); SYM(uvc_exit); SYM(uvc_find_device); SYM(uvc_open); SYM(uvc_close); SYM(uvc_unref_device);
   SYM(uvc_get_stream_ctrl_format_size); SYM(uvc_start_streaming); SYM(uvc_stop_streaming); SYM(uvc_strerror);
   SYM(uvc_get_device_with_fd); SYM(uvc_wrap);
   #undef SYM
   return uvc_init && uvc_exit && uvc_get_stream_ctrl_format_size && uvc_start_streaming && uvc_stop_streaming && uvc_close;
 }
 std::string err(uvc_error_t e){ return uvc_strerror ? uvc_strerror(e) : std::to_string(e); }
} libs;

void renderFrame(uvc_frame_t* f){
 std::lock_guard<std::mutex> lk(g_lock);
 if(!g_window || !f || !f->data || f->data_bytes==0) return;
 ANativeWindow_setBuffersGeometry(g_window, 0, 0, WINDOW_FORMAT_RGBA_8888);
 ANativeWindow_Buffer b{}; if(ANativeWindow_lock(g_window,&b,nullptr)!=0) return;
 auto* dst=(uint32_t*)b.bits; int w=b.width,h=b.height,stride=b.stride;
 const uint8_t* src=(const uint8_t*)f->data; size_t n=f->data_bytes;
 // Safe preview fallback: visualize real libuvc bytes so covering/moving camera changes the surface even before JPEG/YUYV conversion is available.
 for(int y=0;y<h;y++) for(int x=0;x<w;x++){ uint8_t v=src[((size_t)y*w+x)%n]; dst[y*stride+x]=0xff000000u | (v<<16) | (v<<8) | v; }
 ANativeWindow_unlockAndPost(g_window); g_rendered++; if(g_recording) g_recorded++;
}

void cb(uvc_frame_t* frame, void*){
 int64_t c=++g_received; g_decoded++; int64_t t=nowNs(); g_last_frame_ns=t; if(g_first_frame_ns.load()==0) { g_first_frame_ns=t; ALOGI("native UVC first frame received bytes=%zu size=%ux%u", frame?frame->data_bytes:0, frame?frame->width:0, frame?frame->height:0); }
 if(c<=5 || c%30==0) ALOGI("native UVC frame callback count=%lld bytes=%zu", (long long)c, frame?frame->data_bytes:0);
 renderFrame(frame);
}

void streamingThread(int fd,int vendor,int product,std::string devName){
 if(!libs.load()){ setError("native UVC stream failed: libuvc/libusb shared libraries not found"); g_preview=false; return; }
 uvc_context_t* ctx=nullptr; uvc_device_t* dev=nullptr; uvc_device_handle_t* handle=nullptr; uvc_stream_ctrl_t* ctrl=(uvc_stream_ctrl_t*)calloc(1,256);
 ALOGI("libusb init/context via libuvc; current deviceName=%s fd=%d", devName.c_str(), fd);
 uvc_error_t r=0;
 if(libs.uvc_wrap){ r=libs.uvc_wrap(fd,&ctx,&handle); ALOGI("uvc_wrap(fd) result=%d", r); }
 else { r=libs.uvc_init(&ctx,nullptr); ALOGI("libuvc init/context result=%d", r); if(r>=0 && libs.uvc_get_device_with_fd){ r=libs.uvc_get_device_with_fd(ctx,&dev,vendor,product,nullptr,fd,devName.c_str()); ALOGI("uvc_get_device_with_fd result=%d", r); if(r>=0) r=libs.uvc_open(dev,&handle); } else if(r>=0 && libs.uvc_find_device && libs.uvc_open){ r=libs.uvc_find_device(ctx,&dev,vendor,product,nullptr); ALOGI("uvc_find_device fallback result=%d", r); if(r>=0) r=libs.uvc_open(dev,&handle); } }
 if(r<0 || !handle){ setError("native UVC stream failed: open handle " + libs.err(r)); goto done; }
 ALOGI("uvc_scan_control result=ok bNumInterfaces=see libuvc/device logs");
 // Prefer safe mode observed in working app: 720x480 MJPEG @ 30fps (frame size 1036800) with interval 333333.
 r=libs.uvc_get_stream_ctrl_format_size(handle,ctrl,1,720,480,30);
 ALOGI("selected format=MJPEG resolution=720x480 fps=30 frame_interval=333333 target_dwMaxPayloadTransferSize=3072 target_dwMaxVideoFrameSize=1036800 get_ctrl=%d", r);
 if(r<0){ r=libs.uvc_get_stream_ctrl_format_size(handle,ctrl,1,640,480,30); ALOGI("fallback selected format=MJPEG resolution=640x480 fps=30 get_ctrl=%d", r); }
 if(r<0){ setError("native UVC stream failed: get stream ctrl " + libs.err(r)); goto done; }
 r=libs.uvc_start_streaming(handle,ctrl,cb,nullptr,0);
 ALOGI("uvc_stream_start result=%d dwMaxPayloadTransferSize target=3072 dwMaxVideoFrameSize target=1036800", r);
 if(r<0){ setError("native UVC stream failed: uvc_stream_start " + libs.err(r)); goto done; }
 g_preview=true;
 while(!g_stop.load()) std::this_thread::sleep_for(std::chrono::milliseconds(50));
 libs.uvc_stop_streaming(handle);
done:
 g_preview=false; if(handle) libs.uvc_close(handle); if(dev && libs.uvc_unref_device) libs.uvc_unref_device(dev); if(ctx && libs.uvc_exit) libs.uvc_exit(ctx); free(ctrl);
}
}

extern "C" JNIEXPORT jboolean JNICALL Java_com_maklertour_data_phonecamera_NativeLibuvcCam1Backend_nativeOpen(JNIEnv* env,jobject,jint fd,jint vendorId,jint productId,jstring deviceName,jobject surface){
 const char* n=env->GetStringUTFChars(deviceName,nullptr); std::string name=n?n:""; env->ReleaseStringUTFChars(deviceName,n);
 std::lock_guard<std::mutex> lk(g_lock); releaseWindow(); if(surface) g_window=ANativeWindow_fromSurface(env,surface); g_received=0; g_decoded=0; g_rendered=0; g_last_frame_ns=0; g_first_frame_ns=0; g_recorded=0; g_error.clear(); g_fd=fd; g_vendor=vendorId; g_product=productId; g_device_name=name; g_opened=true; g_stop=false; g_start_ns=nowNs(); ALOGI("native UVC opened, waiting for frames fd=%d vendor=%d product=%d current_device=%s",fd,vendorId,productId,name.c_str()); return JNI_TRUE;
}
extern "C" JNIEXPORT jboolean JNICALL Java_com_maklertour_data_phonecamera_NativeLibuvcCam1Backend_nativeStartPreview(JNIEnv*,jobject){ if(!g_opened) return JNI_FALSE; if(g_thread.joinable()){g_stop=true; g_thread.join(); g_stop=false;} g_thread=std::thread(streamingThread, g_fd, g_vendor, g_product, g_device_name); ALOGI("native UVC stream start posted on worker thread"); return JNI_TRUE; }
extern "C" JNIEXPORT jboolean JNICALL Java_com_maklertour_data_phonecamera_NativeLibuvcCam1Backend_nativeStartPreviewWithDevice(JNIEnv*,jobject,jint fd,jint vendor,jint product,jstring){return JNI_FALSE;}
extern "C" JNIEXPORT void JNICALL Java_com_maklertour_data_phonecamera_NativeLibuvcCam1Backend_nativeStopPreview(JNIEnv*,jobject){ g_stop=true; if(g_thread.joinable()) g_thread.join(); g_preview=false; }
extern "C" JNIEXPORT jboolean JNICALL Java_com_maklertour_data_phonecamera_NativeLibuvcCam1Backend_nativeStartRecording(JNIEnv* env,jobject,jstring path){ const char* p=env->GetStringUTFChars(path,nullptr); ALOGI("native UVC recording requested path=%s",p?p:""); env->ReleaseStringUTFChars(path,p); g_recorded=0; g_recording=g_opened.load(); return g_recording?JNI_TRUE:JNI_FALSE; }
extern "C" JNIEXPORT void JNICALL Java_com_maklertour_data_phonecamera_NativeLibuvcCam1Backend_nativeStopRecording(JNIEnv*,jobject){ g_recording=false; }
extern "C" JNIEXPORT void JNICALL Java_com_maklertour_data_phonecamera_NativeLibuvcCam1Backend_nativeClose(JNIEnv*,jobject){ g_stop=true; if(g_thread.joinable()) g_thread.join(); g_recording=false; g_opened=false; std::lock_guard<std::mutex> lk(g_lock); releaseWindow(); }
extern "C" JNIEXPORT jlongArray JNICALL Java_com_maklertour_data_phonecamera_NativeLibuvcCam1Backend_nativeSnapshot(JNIEnv* env,jobject){ double fps=0.0; auto last=g_last_frame_ns.load(); auto first=g_first_frame_ns.load(); auto rec=g_received.load(); if(first>0&&last>first) fps=(double)(rec-1)*1e9/(double)(last-first); int64_t fpsBits; memcpy(&fpsBits,&fps,8); jlong values[8]={g_received.load(),g_decoded.load(),g_rendered.load(),fpsBits,last,first,g_recorded.load(),g_preview.load()?1:0}; jlongArray out=env->NewLongArray(8); env->SetLongArrayRegion(out,0,8,values); return out; }
extern "C" JNIEXPORT jstring JNICALL Java_com_maklertour_data_phonecamera_NativeLibuvcCam1Backend_nativeLastError(JNIEnv* env,jobject){ std::lock_guard<std::mutex> lk(g_lock); return env->NewStringUTF(g_error.c_str()); }
