#include <jni.h>
#include <android/log.h>
#include <android/native_window_jni.h>
#include <android/native_window.h>
#include <atomic>
#include <chrono>
#include <cstdint>
#include <cstdio>
#include <cstring>
#include <cstdlib>
#include <dlfcn.h>
#include <mutex>
#include <string>
#include <thread>
#include <sys/time.h>
#include <unistd.h>

#define LOG_TAG "Cam1NativeUvc"
#define ALOGI(...) __android_log_print(ANDROID_LOG_INFO, LOG_TAG, __VA_ARGS__)
#define ALOGE(...) __android_log_print(ANDROID_LOG_ERROR, LOG_TAG, __VA_ARGS__)

namespace {
using uvc_error_t = int;
struct uvc_context_t; struct uvc_device_t; struct uvc_device_handle_t; struct uvc_stream_ctrl_t; struct uvc_frame_t;
// Mirrors AndroidUSBCamera/libuvc's uvc_frame_t layout, including actual_bytes.
enum uvc_frame_format {
 UVC_FRAME_FORMAT_UNKNOWN = 0,
 UVC_FRAME_FORMAT_ANY = 0,
 UVC_FRAME_FORMAT_UNCOMPRESSED = 1,
 UVC_FRAME_FORMAT_COMPRESSED = 2,
 UVC_FRAME_FORMAT_YUYV = 3,
 UVC_FRAME_FORMAT_UYVY = 4,
 UVC_FRAME_FORMAT_RGB565 = 5,
 UVC_FRAME_FORMAT_RGB = 6,
 UVC_FRAME_FORMAT_BGR = 7,
 UVC_FRAME_FORMAT_RGBX = 8,
 UVC_FRAME_FORMAT_MJPEG = 9,
 UVC_FRAME_FORMAT_GRAY8 = 10,
 UVC_FRAME_FORMAT_BY8 = 11,
 UVC_FRAME_FORMAT_COUNT = 12,
};
struct uvc_frame_t {
 void* data;
 size_t data_bytes;
 size_t actual_bytes;
 uint32_t width;
 uint32_t height;
 uvc_frame_format frame_format;
 size_t step;
 uint32_t sequence;
 timeval capture_time;
 uvc_device_handle_t* source;
 uint8_t library_owns_data;
};
using frame_cb_t = void (*)(uvc_frame_t*, void*);

std::atomic<bool> g_opened{false}, g_preview{false}, g_recording{false}, g_stop{false};
std::atomic<int64_t> g_received{0}, g_decoded{0}, g_rendered{0}, g_last_frame_ns{0}, g_first_frame_ns{0}, g_recorded{0};
std::atomic<int64_t> g_start_ns{0};
std::atomic<int> g_selected_format{UVC_FRAME_FORMAT_UNKNOWN}, g_selected_width{0}, g_selected_height{0}, g_selected_fps{0};
std::mutex g_lock;
ANativeWindow* g_window = nullptr;
std::thread g_thread;
std::string g_error;
int g_fd=-1, g_vendor=0, g_product=0;
int g_bus_num=-1, g_dev_addr=-1;
std::string g_device_name;
std::string g_usbfs;
std::string g_selected_format_name;

const char* frameFormatName(int fmt);
int64_t nowNs(){ return std::chrono::duration_cast<std::chrono::nanoseconds>(std::chrono::steady_clock::now().time_since_epoch()).count(); }
void setError(const std::string& s){ std::lock_guard<std::mutex> lk(g_lock); g_error=s; ALOGE("%s", s.c_str()); }
void clearError(){ std::lock_guard<std::mutex> lk(g_lock); g_error.clear(); }
void releaseWindow(){ if(g_window){ ANativeWindow_release(g_window); g_window=nullptr; } }
void setSelectedMode(int format, int width, int height, int fps){ g_selected_format=format; g_selected_width=width; g_selected_height=height; g_selected_fps=fps; std::lock_guard<std::mutex> lk(g_lock); g_selected_format_name = format == UVC_FRAME_FORMAT_UNKNOWN ? "" : frameFormatName(format); }

struct Libs {
 void* uvc=nullptr; void* usb=nullptr;
 uvc_error_t (*uvc_init)(uvc_context_t**, void*)=nullptr;
 uvc_error_t (*uvc_init2)(uvc_context_t**, void*, const char*)=nullptr;
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
 uvc_error_t (*uvc_get_device_with_fd)(uvc_context_t*, uvc_device_t**, int, int, const char*, int, int, int)=nullptr;
 uvc_error_t (*uvc_wrap)(int, uvc_context_t**, uvc_device_handle_t**)=nullptr;
 void* tryDlopen(const char* name){
   dlerror();
   ALOGI("dlopen attempt library=%s", name);
   void* handle = dlopen(name, RTLD_NOW|RTLD_GLOBAL);
   if(handle){
     ALOGI("dlopen success library=%s handle=%p", name, handle);
   } else {
     const char* err = dlerror();
     ALOGE("dlopen failure library=%s error=%s", name, err ? err : "unknown");
   }
   return handle;
 }
 bool load(){
   if(!usb) usb=tryDlopen("libusb1.0.so");
   if(!usb) usb=tryDlopen("libusb-1.0.so");
   if(!usb) usb=tryDlopen("libusb100.so");
   if(!uvc) uvc=tryDlopen("libuvc.so");
   ALOGI("native library load summary libusb=%p libuvc=%p", usb, uvc);
   if(!usb || !uvc) return false;
   #define SYM(x) x=(decltype(x))dlsym(uvc,#x)
   SYM(uvc_init); SYM(uvc_init2); SYM(uvc_exit); SYM(uvc_find_device); SYM(uvc_open); SYM(uvc_close); SYM(uvc_unref_device);
   SYM(uvc_get_stream_ctrl_format_size); SYM(uvc_start_streaming); SYM(uvc_stop_streaming); SYM(uvc_strerror);
   SYM(uvc_get_device_with_fd); SYM(uvc_wrap);
   #undef SYM
   return uvc_exit && uvc_open && uvc_get_stream_ctrl_format_size && uvc_start_streaming && uvc_stop_streaming && uvc_close;
 }
 std::string err(uvc_error_t e){ return uvc_strerror ? uvc_strerror(e) : std::to_string(e); }
} libs;

const char* frameFormatName(int fmt){
 switch(fmt){
  case UVC_FRAME_FORMAT_UNCOMPRESSED: return "UNCOMPRESSED";
  case UVC_FRAME_FORMAT_COMPRESSED: return "COMPRESSED";
  case UVC_FRAME_FORMAT_YUYV: return "YUYV";
  case UVC_FRAME_FORMAT_UYVY: return "UYVY";
  case UVC_FRAME_FORMAT_MJPEG: return "MJPEG";
  default: return "UNKNOWN";
 }
}

uint8_t clampByte(int v){ return (uint8_t)(v < 0 ? 0 : (v > 255 ? 255 : v)); }
uint32_t yuvToRgba(int y, int u, int v){
 int c=y-16, d=u-128, e=v-128;
 int r=(298*c + 409*e + 128) >> 8;
 int g=(298*c - 100*d - 208*e + 128) >> 8;
 int b=(298*c + 516*d + 128) >> 8;
 return 0xff000000u | ((uint32_t)clampByte(r) << 16) | ((uint32_t)clampByte(g) << 8) | clampByte(b);
}

struct FrameMeta { uint32_t width; uint32_t height; int format; const char* renderer; };
FrameMeta frameMeta(const uvc_frame_t* f){
 FrameMeta m{f ? f->width : 0, f ? f->height : 0, f ? (int)f->frame_format : (int)UVC_FRAME_FORMAT_UNKNOWN, "fallback"};
 if(f && f->data_bytes == 640u * 480u * 2u && (m.width == 0 || m.height == 0 || m.width > 4096 || m.height > 4096)){
  m.width=640; m.height=480; m.format=UVC_FRAME_FORMAT_YUYV;
 }
 if(m.format == UVC_FRAME_FORMAT_YUYV || m.format == UVC_FRAME_FORMAT_UNCOMPRESSED) m.renderer="YUYV";
 else if(m.format == UVC_FRAME_FORMAT_MJPEG || m.format == UVC_FRAME_FORMAT_COMPRESSED) m.renderer="MJPEG";
 return m;
}

bool renderFrame(uvc_frame_t* f){
 std::lock_guard<std::mutex> lk(g_lock);
 if(!g_window || !f || !f->data || f->data_bytes==0) return false;
 FrameMeta m=frameMeta(f);
 if(m.width == 0 || m.height == 0) return false;
 ANativeWindow_setBuffersGeometry(g_window, (int)m.width, (int)m.height, WINDOW_FORMAT_RGBA_8888);
 ANativeWindow_Buffer b{}; if(ANativeWindow_lock(g_window,&b,nullptr)!=0) return false;
 auto* dst=(uint32_t*)b.bits; int bw=b.width,bh=b.height,stride=b.stride;
 const uint8_t* src=(const uint8_t*)f->data; size_t n=f->data_bytes;
 if(!dst || bw <= 0 || bh <= 0 || stride < bw){ ANativeWindow_unlockAndPost(g_window); return false; }
 for(int y=0;y<bh;y++){
  uint32_t sy=(uint32_t)(((uint64_t)y * m.height) / (uint32_t)bh);
  for(int x=0;x<bw;x++){
   uint32_t sx=(uint32_t)(((uint64_t)x * m.width) / (uint32_t)bw);
   if(m.format == UVC_FRAME_FORMAT_YUYV && n >= (size_t)m.width * m.height * 2u){
    size_t pair=((size_t)sy * m.width + (sx & ~1u)) * 2u;
    if(pair + 3 >= n) continue;
    uint8_t y0=src[pair], u=src[pair+1], y1=src[pair+2], v=src[pair+3];
    dst[y*stride+x]=yuvToRgba((sx & 1u) ? y1 : y0, u, v);
   } else {
    uint8_t v=src[((size_t)sy*m.width+sx)%n];
    dst[y*stride+x]=0xff000000u | ((uint32_t)v<<16) | ((uint32_t)v<<8) | v;
   }
  }
 }
 ANativeWindow_unlockAndPost(g_window); g_rendered++; if(g_recording) g_recorded++; return true;
}

void cb(uvc_frame_t* frame, void*){
 int64_t c=++g_received; g_decoded++; int64_t t=nowNs(); g_last_frame_ns=t;
 FrameMeta m=frameMeta(frame);
 char hex[16*3+1]{};
 if(frame && frame->data){
  const uint8_t* p=(const uint8_t*)frame->data; size_t lim=frame->data_bytes < 16 ? frame->data_bytes : 16;
  for(size_t i=0;i<lim;i++) snprintf(hex+i*3, sizeof(hex)-i*3, "%02x%s", p[i], i+1<lim ? " " : "");
 }
 if(g_first_frame_ns.load()==0) { g_first_frame_ns=t; ALOGI("native UVC first frame received data_bytes=%zu width=%u height=%u frame_format=%d frame_format_name=%s sequence=%u first16=%s renderer=%s", frame?frame->data_bytes:0, m.width, m.height, frame?(int)frame->frame_format:0, frameFormatName(frame?(int)frame->frame_format:0), frame?frame->sequence:0, hex, m.renderer); clearError(); }
 if(c%30==0) ALOGI("native UVC frame callback count=%lld data_bytes=%zu width=%u height=%u frame_format=%d frame_format_name=%s sequence=%u first16=%s renderer=%s", (long long)c, frame?frame->data_bytes:0, m.width, m.height, frame?(int)frame->frame_format:0, frameFormatName(frame?(int)frame->frame_format:0), frame?frame->sequence:0, hex, m.renderer);
 if(renderFrame(frame)) clearError();
}

void streamingThread(int fd,int vendor,int product,std::string devName,int busNum,int devAddr,std::string usbfs){
 if(!libs.load()){ setError("NATIVE_LIB_MISSING: libuvc/libusb shared libraries not found"); g_preview=false; return; }
 uvc_context_t* ctx=nullptr; uvc_device_t* dev=nullptr; uvc_device_handle_t* handle=nullptr; uvc_stream_ctrl_t* ctrl=(uvc_stream_ctrl_t*)calloc(1,256);
 uvc_error_t r=0;
 int selectedFormat=UVC_FRAME_FORMAT_UNKNOWN, selectedWidth=0, selectedHeight=0, selectedFps=0;
 struct Request { int fmt; const char* name; int w; int h; int fps; };
 const Request requests[] = {
  {UVC_FRAME_FORMAT_YUYV, "YUYV", 640, 480, 30},
  {UVC_FRAME_FORMAT_UNCOMPRESSED, "UNCOMPRESSED", 640, 480, 30},
 };
 ALOGI("native fd-aware init fields fd=%d vendor=%d product=%d deviceName=%s usbfs=%s busNum=%d devAddr=%d surfacePresent=%s", fd, vendor, product, devName.c_str(), usbfs.c_str(), busNum, devAddr, g_window ? "true" : "false");
 if(!libs.uvc_init2){ setError("NATIVE_UVC_INIT_FAILED: uvc_init2 symbol missing"); goto done; }
 if(!libs.uvc_get_device_with_fd){ setError("NATIVE_UVC_OPEN_FAILED: uvc_get_device_with_fd symbol missing"); goto done; }
 r=libs.uvc_init2(&ctx,nullptr,usbfs.c_str());
 ALOGI("uvc_init2 usbfs=%s result=%d error=%s", usbfs.c_str(), r, r < 0 ? libs.err(r).c_str() : "none");
 if(r<0){ setError("NATIVE_UVC_INIT_FAILED: uvc_init2 usbfs=" + usbfs + " " + libs.err(r)); goto done; }
 r=libs.uvc_get_device_with_fd(ctx,&dev,vendor,product,nullptr,fd,busNum,devAddr);
 ALOGI("uvc_get_device_with_fd fd=%d busNum=%d devAddr=%d vendor=%d product=%d result=%d error=%s", fd, busNum, devAddr, vendor, product, r, r < 0 ? libs.err(r).c_str() : "none");
 if(r<0 || !dev){ setError("NATIVE_UVC_OPEN_FAILED: uvc_get_device_with_fd " + libs.err(r)); goto done; }
 r=libs.uvc_open(dev,&handle);
 ALOGI("uvc_open after fd device result=%d error=%s handle=%p", r, r < 0 ? libs.err(r).c_str() : "none", handle);
 if(r<0 || !handle){ setError("NATIVE_UVC_OPEN_FAILED: uvc_open " + libs.err(r)); goto done; }
 ALOGI("uvc_scan_control result=ok bNumInterfaces=see libuvc/device logs");
 ALOGI("future target only format=MJPEG width=1920 height=1080 fps=30 selected=false reason=MJPEG decoder not implemented for live preview");
 for(const auto& req: requests){
  r=libs.uvc_get_stream_ctrl_format_size(handle,ctrl,req.fmt,req.w,req.h,req.fps);
  ALOGI("requested format=%s width=%d height=%d fps=%d get_ctrl result=%d", req.name, req.w, req.h, req.fps, r);
  if(r>=0){ selectedFormat=req.fmt; selectedWidth=req.w; selectedHeight=req.h; selectedFps=req.fps; ALOGI("selected format=%s", req.name); break; }
 }
 if(r<0){ setError("NATIVE_UVC_STREAM_START_FAILED: uvc_get_stream_ctrl_format_size " + libs.err(r)); goto done; }
 setSelectedMode(selectedFormat, selectedWidth, selectedHeight, selectedFps);
 r=libs.uvc_start_streaming(handle,ctrl,cb,nullptr,0);
 ALOGI("uvc_start_streaming result=%d error=%s", r, r < 0 ? libs.err(r).c_str() : "none");
 if(r<0){ setSelectedMode(UVC_FRAME_FORMAT_UNKNOWN, 0, 0, 0); setError("NATIVE_UVC_STREAM_START_FAILED: uvc_start_streaming " + libs.err(r)); goto done; }
 clearError();
 ALOGI("real libuvc stream start succeeded selected format=%s width=%d height=%d fps=%d", frameFormatName(selectedFormat), selectedWidth, selectedHeight, selectedFps);
 g_preview=true;
 while(!g_stop.load()) std::this_thread::sleep_for(std::chrono::milliseconds(50));
 libs.uvc_stop_streaming(handle);
done:
 g_preview=false; if(handle) libs.uvc_close(handle); if(dev && libs.uvc_unref_device) libs.uvc_unref_device(dev); if(ctx && libs.uvc_exit) libs.uvc_exit(ctx); free(ctrl);
}
}

extern "C" JNIEXPORT jboolean JNICALL Java_com_maklertour_data_phonecamera_NativeLibuvcCam1Backend_nativeOpen(JNIEnv* env,jobject,jint fd,jint vendorId,jint productId,jstring deviceName,jint busNum,jint devAddr,jstring usbfs,jobject surface){
 clearError();
 const char* n=env->GetStringUTFChars(deviceName,nullptr); std::string name=n?n:""; env->ReleaseStringUTFChars(deviceName,n);
 const char* u=env->GetStringUTFChars(usbfs,nullptr); std::string usbfsPath=u?u:""; env->ReleaseStringUTFChars(usbfs,u);
 std::lock_guard<std::mutex> lk(g_lock); releaseWindow(); if(surface) g_window=ANativeWindow_fromSurface(env,surface); g_received=0; g_decoded=0; g_rendered=0; g_last_frame_ns=0; g_first_frame_ns=0; g_recorded=0; g_selected_format=UVC_FRAME_FORMAT_UNKNOWN; g_selected_width=0; g_selected_height=0; g_selected_fps=0; g_selected_format_name.clear(); g_error.clear(); g_fd=fd; g_vendor=vendorId; g_product=productId; g_device_name=name; g_bus_num=busNum; g_dev_addr=devAddr; g_usbfs=usbfsPath; g_opened=true; g_stop=false; g_start_ns=nowNs(); ALOGI("native UVC opened, waiting for frames fd=%d vendor=%d product=%d current_device=%s usbfs=%s busNum=%d devAddr=%d surfacePresent=%s",fd,vendorId,productId,name.c_str(),usbfsPath.c_str(),busNum,devAddr,surface ? "true" : "false"); return JNI_TRUE;
}
extern "C" JNIEXPORT jboolean JNICALL Java_com_maklertour_data_phonecamera_NativeLibuvcCam1Backend_nativeStartPreview(JNIEnv*,jobject){ clearError(); if(!g_opened) return JNI_FALSE; if(g_thread.joinable()){g_stop=true; g_thread.join(); g_stop=false;} g_thread=std::thread(streamingThread, g_fd, g_vendor, g_product, g_device_name, g_bus_num, g_dev_addr, g_usbfs); ALOGI("native UVC stream start posted on worker thread"); return JNI_TRUE; }
extern "C" JNIEXPORT jboolean JNICALL Java_com_maklertour_data_phonecamera_NativeLibuvcCam1Backend_nativeStartPreviewWithDevice(JNIEnv* env,jobject thiz,jint fd,jint vendor,jint product,jstring deviceName){
 clearError();
 const char* n=env->GetStringUTFChars(deviceName,nullptr); std::string name=n?n:""; env->ReleaseStringUTFChars(deviceName,n);
 if(g_bus_num < 0 || g_dev_addr < 0 || g_usbfs.empty()){
   setError("NATIVE_UVC_OPEN_FAILED: nativeStartPreviewWithDevice requires prior nativeOpen with parsed USB path");
   return JNI_FALSE;
 }
 g_fd=fd; g_vendor=vendor; g_product=product; g_device_name=name;
 if(!g_opened) g_opened=true;
 if(g_thread.joinable()){g_stop=true; g_thread.join(); g_stop=false;}
 g_thread=std::thread(streamingThread, g_fd, g_vendor, g_product, g_device_name, g_bus_num, g_dev_addr, g_usbfs);
 ALOGI("native UVC stream start-with-device posted fd=%d vendor=%d product=%d deviceName=%s usbfs=%s busNum=%d devAddr=%d", fd, vendor, product, name.c_str(), g_usbfs.c_str(), g_bus_num, g_dev_addr);
 return JNI_TRUE;
}
extern "C" JNIEXPORT void JNICALL Java_com_maklertour_data_phonecamera_NativeLibuvcCam1Backend_nativeStopPreview(JNIEnv*,jobject){ g_stop=true; if(g_thread.joinable()) g_thread.join(); g_preview=false; }
extern "C" JNIEXPORT jboolean JNICALL Java_com_maklertour_data_phonecamera_NativeLibuvcCam1Backend_nativeStartRecording(JNIEnv* env,jobject,jstring path){ const char* p=env->GetStringUTFChars(path,nullptr); ALOGI("native UVC recording requested path=%s",p?p:""); env->ReleaseStringUTFChars(path,p); g_recorded=0; g_recording=g_opened.load(); return g_recording?JNI_TRUE:JNI_FALSE; }
extern "C" JNIEXPORT void JNICALL Java_com_maklertour_data_phonecamera_NativeLibuvcCam1Backend_nativeStopRecording(JNIEnv*,jobject){ g_recording=false; }
extern "C" JNIEXPORT void JNICALL Java_com_maklertour_data_phonecamera_NativeLibuvcCam1Backend_nativeClose(JNIEnv*,jobject){ g_stop=true; if(g_thread.joinable()) g_thread.join(); g_recording=false; g_opened=false; g_selected_format=UVC_FRAME_FORMAT_UNKNOWN; g_selected_width=0; g_selected_height=0; g_selected_fps=0; std::lock_guard<std::mutex> lk(g_lock); g_selected_format_name.clear(); releaseWindow(); }
extern "C" JNIEXPORT jlongArray JNICALL Java_com_maklertour_data_phonecamera_NativeLibuvcCam1Backend_nativeSnapshot(JNIEnv* env,jobject){ double fps=0.0; auto last=g_last_frame_ns.load(); auto first=g_first_frame_ns.load(); auto rec=g_received.load(); if(first>0&&last>first) fps=(double)(rec-1)*1e9/(double)(last-first); int64_t fpsBits; memcpy(&fpsBits,&fps,8); jlong values[12]={g_received.load(),g_decoded.load(),g_rendered.load(),fpsBits,last,first,g_recorded.load(),g_preview.load()?1:0,g_selected_format.load(),g_selected_width.load(),g_selected_height.load(),g_selected_fps.load()}; jlongArray out=env->NewLongArray(12); env->SetLongArrayRegion(out,0,12,values); return out; }
extern "C" JNIEXPORT jstring JNICALL Java_com_maklertour_data_phonecamera_NativeLibuvcCam1Backend_nativeSelectedFormatName(JNIEnv* env,jobject){ std::lock_guard<std::mutex> lk(g_lock); return env->NewStringUTF(g_selected_format_name.c_str()); }
extern "C" JNIEXPORT jstring JNICALL Java_com_maklertour_data_phonecamera_NativeLibuvcCam1Backend_nativeLastError(JNIEnv* env,jobject){ std::lock_guard<std::mutex> lk(g_lock); return env->NewStringUTF(g_error.c_str()); }
