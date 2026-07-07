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
#include <condition_variable>
#include <mutex>
#include <vector>
#include <string>
#include <thread>
#include <sys/time.h>
#include <time.h>
#include <unistd.h>
#include <errno.h>

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
std::atomic<bool> g_accept_frames{false}, g_render_stop{false}, g_frame_dirty{false};
std::atomic<int64_t> g_received{0}, g_decoded{0}, g_rendered{0}, g_last_frame_ns{0}, g_first_frame_ns{0}, g_recorded{0};
std::atomic<int64_t> g_start_ns{0}, g_session_generation{0}, g_latest_sequence{0};
std::atomic<int> g_selected_format{UVC_FRAME_FORMAT_UNKNOWN}, g_selected_width{0}, g_selected_height{0}, g_selected_fps{0};
std::atomic<int> g_callbacks_in_flight{0};
struct CallbackState;
std::atomic<CallbackState*> g_active_callback_state{nullptr};
std::atomic<int64_t> g_render_errors{0}, g_window_lock_failures{0}, g_surface_null_count{0}, g_callback_dropped_after_stop{0}, g_lifecycle_restarts{0};
std::mutex g_lifecycle_lock;
std::mutex g_state_lock;
std::mutex g_window_lock;
std::mutex g_frame_lock;
std::mutex g_record_lock;
std::condition_variable g_frame_cv;
ANativeWindow* g_window = nullptr;
std::thread g_thread;
std::thread g_render_thread;
std::string g_error;
int g_fd=-1, g_vendor=0, g_product=0;
int g_bus_num=-1, g_dev_addr=-1;
std::string g_device_name;
std::string g_usbfs;
std::string g_selected_format_name;
std::string g_preferred_format_name;
int g_preferred_width=0, g_preferred_height=0, g_preferred_fps=0;
bool g_preferred_auto=true;
std::vector<uint8_t> g_latest_frame;
uint32_t g_latest_width=0, g_latest_height=0;
int g_latest_format=UVC_FRAME_FORMAT_UNKNOWN;
int64_t g_latest_timestamp_ns=0;
int64_t g_latest_frame_sequence=0;
FILE* g_record_file=nullptr;

const char* frameFormatName(int fmt);
int64_t nowNs(){
 timespec ts{};
#ifdef CLOCK_BOOTTIME
 if(clock_gettime(CLOCK_BOOTTIME, &ts) == 0) return (int64_t)ts.tv_sec * 1000000000LL + ts.tv_nsec;
#endif
 if(clock_gettime(CLOCK_MONOTONIC, &ts) == 0) return (int64_t)ts.tv_sec * 1000000000LL + ts.tv_nsec;
 return std::chrono::duration_cast<std::chrono::nanoseconds>(std::chrono::steady_clock::now().time_since_epoch()).count();
}
void setError(const std::string& s){ std::lock_guard<std::mutex> lk(g_state_lock); g_error=s; ALOGE("%s", s.c_str()); }
void clearError(){ std::lock_guard<std::mutex> lk(g_state_lock); g_error.clear(); }
void releaseWindowLocked(){ if(g_window){ ANativeWindow_release(g_window); g_window=nullptr; } }
void setSelectedMode(int format, int width, int height, int fps){ g_selected_format=format; g_selected_width=width; g_selected_height=height; g_selected_fps=fps; std::lock_guard<std::mutex> lk(g_state_lock); g_selected_format_name = format == UVC_FRAME_FORMAT_UNKNOWN ? "" : frameFormatName(format); }

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
void yuvToRgbaBytes(int y, int u, int v, uint8_t* out){
 int c=y-16, d=u-128, e=v-128;
 int r=(298*c + 409*e + 128) >> 8;
 int g=(298*c - 100*d - 208*e + 128) >> 8;
 int b=(298*c + 516*d + 128) >> 8;
 out[0]=clampByte(r); out[1]=clampByte(g); out[2]=clampByte(b); out[3]=255;
}

struct TurboJpeg {
 void* handle=nullptr;
 bool attempted=false;
 bool available=false;
 void* (*tjInitDecompress)()=nullptr;
 int (*tjDecompressHeader3)(void*, const unsigned char*, unsigned long, int*, int*, int*, int*)=nullptr;
 int (*tjDecompress2)(void*, const unsigned char*, unsigned long, unsigned char*, int, int, int, int, int)=nullptr;
 int (*tjDestroy)(void*)=nullptr;
 bool load(){
  if(attempted) return available;
  attempted=true;
  const char* loadedName="none";
  handle=dlopen("libjpeg-turbo1500.so", RTLD_NOW|RTLD_LOCAL);
  if(handle) loadedName="libjpeg-turbo1500.so";
  if(!handle){
   handle=dlopen("libturbojpeg.so", RTLD_NOW|RTLD_LOCAL);
   if(handle) loadedName="libturbojpeg.so";
  }
  if(handle){
   tjInitDecompress=(decltype(tjInitDecompress))dlsym(handle,"tjInitDecompress");
   tjDecompressHeader3=(decltype(tjDecompressHeader3))dlsym(handle,"tjDecompressHeader3");
   tjDecompress2=(decltype(tjDecompress2))dlsym(handle,"tjDecompress2");
   tjDestroy=(decltype(tjDestroy))dlsym(handle,"tjDestroy");
  }
  available=handle && tjInitDecompress && tjDecompressHeader3 && tjDecompress2 && tjDestroy;
  ALOGI("turbojpeg symbol load summary library=%s handle=%p tjInitDecompress=%s tjDecompressHeader3=%s tjDecompress2=%s tjDestroy=%s available=%s", loadedName, handle, tjInitDecompress ? "true" : "false", tjDecompressHeader3 ? "true" : "false", tjDecompress2 ? "true" : "false", tjDestroy ? "true" : "false", available ? "true" : "false");
  ALOGI("MJPEG decoder availability turbojpeg=%s", available ? "true" : "false");
  return available;
 }
} turbojpeg;
std::mutex g_turbojpeg_lock;

bool ensureTurboJpeg(){ std::lock_guard<std::mutex> lk(g_turbojpeg_lock); return turbojpeg.load(); }

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

bool renderYuyvToWindow(const std::vector<uint8_t>& frame, uint32_t width, uint32_t height, int format){
 if(frame.empty() || width == 0 || height == 0) return false;
 std::lock_guard<std::mutex> windowGuard(g_window_lock);
 ANativeWindow* window = g_window;
 if(!window){ g_surface_null_count++; return false; }
 ANativeWindow_Buffer b{};
 if(ANativeWindow_lock(window, &b, nullptr) != 0){
  g_window_lock_failures++; ALOGE("ANativeWindow_lock failed generation=%lld", (long long)g_session_generation.load()); return false;
 }
 auto* dst=(uint8_t*)b.bits; int bw=b.width,bh=b.height,stride=b.stride;
 if(!dst || bw <= 0 || bh <= 0 || stride < bw){ ANativeWindow_unlockAndPost(window); g_render_errors++; return false; }
 const uint8_t* src=frame.data(); size_t n=frame.size();
 for(int y=0;y<bh;y++){
  uint32_t sy=(uint32_t)(((uint64_t)y * height) / (uint32_t)bh);
  uint8_t* row = dst + (size_t)y * stride * 4u;
  for(int x=0;x<bw;x++){
   uint32_t sx=(uint32_t)(((uint64_t)x * width) / (uint32_t)bw);
   uint8_t* px = row + (size_t)x * 4u;
   if((format == UVC_FRAME_FORMAT_YUYV || format == UVC_FRAME_FORMAT_UNCOMPRESSED) && n >= (size_t)width * height * 2u){
    size_t pair=((size_t)sy * width + (sx & ~1u)) * 2u;
    if(pair + 3 >= n) continue;
    uint8_t y0=src[pair], u=src[pair+1], y1=src[pair+2], v=src[pair+3];
    yuvToRgbaBytes((sx & 1u) ? y1 : y0, u, v, px);
   } else if(format == UVC_FRAME_FORMAT_UYVY && n >= (size_t)width * height * 2u){
    size_t pair=((size_t)sy * width + (sx & ~1u)) * 2u;
    if(pair + 3 >= n) continue;
    uint8_t u=src[pair], y0=src[pair+1], v=src[pair+2], y1=src[pair+3];
    yuvToRgbaBytes((sx & 1u) ? y1 : y0, u, v, px);
   } else {
    uint8_t v=src[((size_t)sy*width+sx)%n];
    px[0]=v; px[1]=v; px[2]=v; px[3]=255;
   }
  }
 }
 if(ANativeWindow_unlockAndPost(window) != 0){ g_render_errors++; return false; }
 g_decoded++; g_rendered++; return true;
}

bool renderRgbaToWindow(const std::vector<uint8_t>& rgba, uint32_t width, uint32_t height){
 if(rgba.empty() || width == 0 || height == 0 || rgba.size() < (size_t)width * height * 4u) return false;
 std::lock_guard<std::mutex> windowGuard(g_window_lock);
 ANativeWindow* window = g_window;
 if(!window){ g_surface_null_count++; return false; }
 ANativeWindow_Buffer b{};
 if(ANativeWindow_lock(window, &b, nullptr) != 0){
  g_window_lock_failures++; ALOGE("ANativeWindow_lock failed generation=%lld", (long long)g_session_generation.load()); return false;
 }
 auto* dst=(uint8_t*)b.bits; int bw=b.width,bh=b.height,stride=b.stride;
 if(!dst || bw <= 0 || bh <= 0 || stride < bw){ ANativeWindow_unlockAndPost(window); g_render_errors++; return false; }
 for(int y=0;y<bh;y++){
  uint32_t sy=(uint32_t)(((uint64_t)y * height) / (uint32_t)bh);
  uint8_t* row = dst + (size_t)y * stride * 4u;
  for(int x=0;x<bw;x++){
   uint32_t sx=(uint32_t)(((uint64_t)x * width) / (uint32_t)bw);
   const uint8_t* sp = rgba.data() + ((size_t)sy * width + sx) * 4u;
   uint8_t* dp = row + (size_t)x * 4u;
   dp[0]=sp[0]; dp[1]=sp[1]; dp[2]=sp[2]; dp[3]=sp[3];
  }
 }
 if(ANativeWindow_unlockAndPost(window) != 0){ g_render_errors++; return false; }
 g_decoded++; g_rendered++; return true;
}

bool renderMjpegToWindow(const std::vector<uint8_t>& frame){
 if(frame.empty() || !ensureTurboJpeg()) return false;
 void* dec=turbojpeg.tjInitDecompress();
 if(!dec) return false;
 int w=0,h=0,subsamp=0,cs=0;
 bool ok=false;
 if(turbojpeg.tjDecompressHeader3(dec, frame.data(), (unsigned long)frame.size(), &w, &h, &subsamp, &cs) == 0 && w > 0 && h > 0){
  std::vector<uint8_t> rgba((size_t)w * (size_t)h * 4u);
  constexpr int TJPF_RGBA = 7;
  if(turbojpeg.tjDecompress2(dec, frame.data(), (unsigned long)frame.size(), rgba.data(), w, 0, h, TJPF_RGBA, 0) == 0){
   ok = renderRgbaToWindow(rgba, (uint32_t)w, (uint32_t)h);
  }
 }
 turbojpeg.tjDestroy(dec);
 return ok;
}

bool renderFrameToWindow(const std::vector<uint8_t>& frame, uint32_t width, uint32_t height, int format){
 if(format == UVC_FRAME_FORMAT_MJPEG || format == UVC_FRAME_FORMAT_COMPRESSED) return renderMjpegToWindow(frame);
 return renderYuyvToWindow(frame, width, height, format);
}

void renderThread(){
 ALOGI("render thread start");
 std::vector<uint8_t> local;
 uint32_t w=0,h=0; int fmt=UVC_FRAME_FORMAT_UNKNOWN;
 while(true){
  {
   std::unique_lock<std::mutex> lk(g_frame_lock);
   g_frame_cv.wait(lk, []{ return g_render_stop.load() || g_frame_dirty.load(); });
   if(g_render_stop.load() && !g_frame_dirty.load()) break;
   local = g_latest_frame; w = g_latest_width; h = g_latest_height; fmt = g_latest_format; g_frame_dirty=false;
  }
  if(!renderFrameToWindow(local, w, h, fmt)) g_render_errors++;
 }
 ALOGI("render thread stop");
}

void ensureRenderThreadLocked(){
 if(g_render_thread.joinable()) return;
 g_render_stop=false;
 g_render_thread=std::thread(renderThread);
}

void stopRenderThreadLocked(){
 g_render_stop=true;
 g_frame_cv.notify_all();
 if(g_render_thread.joinable()) g_render_thread.join();
}

void requestStreamStopLocked(){
 g_accept_frames=false;
 g_active_callback_state.store(nullptr);
 g_stop=true;
 g_session_generation++;
 g_frame_cv.notify_all();
}

struct CallbackState { int64_t generation; };
struct CallbackGuard { CallbackGuard(){ g_callbacks_in_flight++; } ~CallbackGuard(){ g_callbacks_in_flight--; } };

void noteDroppedCallback(){
 int64_t dropped = ++g_callback_dropped_after_stop;
 if(dropped % 100 == 0) ALOGI("callback dropped after stop/stale count=%lld", (long long)dropped);
}

void cb(uvc_frame_t* frame, void* user){
 auto* state = static_cast<CallbackState*>(user);
 if(state == nullptr || state != g_active_callback_state.load()){
  noteDroppedCallback(); return;
 }
 CallbackGuard guard;
 if(state != g_active_callback_state.load() || !g_accept_frames.load()){
  noteDroppedCallback(); return;
 }
 int64_t gen = state->generation;
 int64_t current = g_session_generation.load();
 if(gen != current){ noteDroppedCallback(); return; }
 FrameMeta m=frameMeta(frame);
 if(!frame || !frame->data || frame->data_bytes == 0 || m.width == 0 || m.height == 0) return;
 size_t bytes = frame->actual_bytes > 0 ? frame->actual_bytes : frame->data_bytes;
 if(bytes > frame->data_bytes) bytes = frame->data_bytes;
 size_t expected = (size_t)m.width * m.height * 2u;
 size_t copyBytes = 0;
 if(m.format == UVC_FRAME_FORMAT_MJPEG || m.format == UVC_FRAME_FORMAT_COMPRESSED){
  copyBytes = bytes;
 } else if(m.format == UVC_FRAME_FORMAT_YUYV || m.format == UVC_FRAME_FORMAT_UYVY || m.format == UVC_FRAME_FORMAT_UNCOMPRESSED){
  if(bytes < expected) return;
  copyBytes = expected;
 } else {
  return;
 }
 if(copyBytes == 0 || copyBytes > frame->data_bytes) return;
 if(g_recording.load()){
  if(m.format == UVC_FRAME_FORMAT_MJPEG || m.format == UVC_FRAME_FORMAT_COMPRESSED){
   std::lock_guard<std::mutex> recordGuard(g_record_lock);
   if(g_recording.load() && g_record_file){
    size_t written = fwrite(frame->data, 1, copyBytes, g_record_file);
    if(written == copyBytes) { g_recorded++; }
    else { setError("NATIVE_UVC_RECORD_WRITE_FAILED: fwrite failed"); g_recording=false; }
   }
  }
 }
 int64_t c=++g_received; int64_t t=nowNs(); g_last_frame_ns=t;
 {
  std::lock_guard<std::mutex> lk(g_frame_lock);
  g_latest_frame.assign((const uint8_t*)frame->data, (const uint8_t*)frame->data + copyBytes);
  g_latest_width=m.width; g_latest_height=m.height; g_latest_format=m.format; g_latest_timestamp_ns=t; g_latest_frame_sequence=frame->sequence > 0 ? (int64_t)frame->sequence : c; g_latest_sequence=g_latest_frame_sequence; g_frame_dirty=true;
 }
 g_frame_cv.notify_one();
 char hex[16*3+1]{};
 const uint8_t* p=(const uint8_t*)frame->data; size_t lim=frame->data_bytes < 16 ? frame->data_bytes : 16;
 for(size_t i=0;i<lim;i++) snprintf(hex+i*3, sizeof(hex)-i*3, "%02x%s", p[i], i+1<lim ? " " : "");
 if(g_first_frame_ns.load()==0) { g_first_frame_ns=t; ALOGI("native UVC first frame received data_bytes=%zu actual_bytes=%zu width=%u height=%u frame_format=%d frame_format_name=%s sequence=%u first16=%s renderer=%s generation=%lld", frame->data_bytes, frame->actual_bytes, m.width, m.height, (int)frame->frame_format, frameFormatName((int)frame->frame_format), frame->sequence, hex, m.renderer, (long long)gen); clearError(); }
 if(c%30==0) ALOGI("native UVC frame callback count=%lld data_bytes=%zu actual_bytes=%zu width=%u height=%u frame_format=%d frame_format_name=%s sequence=%u first16=%s renderer=%s generation=%lld", (long long)c, frame->data_bytes, frame->actual_bytes, m.width, m.height, (int)frame->frame_format, frameFormatName((int)frame->frame_format), frame->sequence, hex, m.renderer, (long long)gen);
}

void streamingThread(int64_t generation,int fd,int vendor,int product,std::string devName,int busNum,int devAddr,std::string usbfs){
 ALOGI("streamingThread start generation=%lld", (long long)generation);
 if(!libs.load()){ setError("NATIVE_LIB_MISSING: libuvc/libusb shared libraries not found"); g_preview=false; return; }
 uvc_context_t* ctx=nullptr; uvc_device_t* dev=nullptr; uvc_device_handle_t* handle=nullptr; uvc_stream_ctrl_t* ctrl=(uvc_stream_ctrl_t*)calloc(1,256);
 auto* cbState = new CallbackState{generation};
 bool streamingStarted=false;
 uvc_error_t r=0;
 int64_t streamStartNs=0;
 int selectedFormat=UVC_FRAME_FORMAT_UNKNOWN, selectedWidth=0, selectedHeight=0, selectedFps=0;
 struct Request { int fmt; const char* name; int w; int h; int fps; };
 const bool mjpegAvailable = ensureTurboJpeg();
 std::vector<Request> requests;
 {
  std::lock_guard<std::mutex> lk(g_state_lock);
  ALOGI("preferred cam1 mode format=%s width=%d height=%d fps=%d auto=%s", g_preferred_format_name.c_str(), g_preferred_width, g_preferred_height, g_preferred_fps, g_preferred_auto ? "true" : "false");
  if(!g_preferred_auto && !g_preferred_format_name.empty() && g_preferred_width > 0 && g_preferred_height > 0 && g_preferred_fps > 0){
   int fmt = UVC_FRAME_FORMAT_UNKNOWN;
   if(g_preferred_format_name == "MJPEG") fmt = UVC_FRAME_FORMAT_MJPEG;
   else if(g_preferred_format_name == "YUYV") fmt = UVC_FRAME_FORMAT_YUYV;
   else if(g_preferred_format_name == "UNCOMPRESSED") fmt = UVC_FRAME_FORMAT_UNCOMPRESSED;
   if(fmt != UVC_FRAME_FORMAT_UNKNOWN) requests.push_back({fmt, g_preferred_format_name.c_str(), g_preferred_width, g_preferred_height, g_preferred_fps});
  }
 }
 requests.push_back({UVC_FRAME_FORMAT_MJPEG, "MJPEG", 1280, 720, 30});
 requests.push_back({UVC_FRAME_FORMAT_MJPEG, "MJPEG", 640, 480, 30});
 requests.push_back({UVC_FRAME_FORMAT_MJPEG, "MJPEG", 1920, 1080, 30});
 requests.push_back({UVC_FRAME_FORMAT_YUYV, "YUYV", 640, 480, 30});
 requests.push_back({UVC_FRAME_FORMAT_UNCOMPRESSED, "UNCOMPRESSED", 640, 480, 30});
 bool surfacePresent=false;
 {
  std::lock_guard<std::mutex> lk(g_window_lock);
  surfacePresent = g_window != nullptr;
 }
 ALOGI("native fd-aware init fields fd=%d vendor=%d product=%d deviceName=%s usbfs=%s busNum=%d devAddr=%d surfacePresent=%s generation=%lld", fd, vendor, product, devName.c_str(), usbfs.c_str(), busNum, devAddr, surfacePresent ? "true" : "false", (long long)generation);
 if(!libs.uvc_init2){ setError("NATIVE_UVC_INIT_FAILED: uvc_init2 symbol missing"); goto done; }
 if(!libs.uvc_get_device_with_fd){ setError("NATIVE_UVC_OPEN_FAILED: uvc_get_device_with_fd symbol missing"); goto done; }
 r=libs.uvc_init2(&ctx,nullptr,usbfs.c_str());
 ALOGI("uvc_init2 usbfs=%s result=%d error=%s generation=%lld", usbfs.c_str(), r, r < 0 ? libs.err(r).c_str() : "none", (long long)generation);
 if(r<0){ setError("NATIVE_UVC_INIT_FAILED: uvc_init2 usbfs=" + usbfs + " " + libs.err(r)); goto done; }
 r=libs.uvc_get_device_with_fd(ctx,&dev,vendor,product,nullptr,fd,busNum,devAddr);
 ALOGI("uvc_get_device_with_fd fd=%d busNum=%d devAddr=%d vendor=%d product=%d result=%d error=%s generation=%lld", fd, busNum, devAddr, vendor, product, r, r < 0 ? libs.err(r).c_str() : "none", (long long)generation);
 if(r<0 || !dev){ setError("NATIVE_UVC_OPEN_FAILED: uvc_get_device_with_fd " + libs.err(r)); goto done; }
 r=libs.uvc_open(dev,&handle);
 ALOGI("uvc_open after fd device result=%d error=%s handle=%p generation=%lld", r, r < 0 ? libs.err(r).c_str() : "none", handle, (long long)generation);
 if(r<0 || !handle){ setError("NATIVE_UVC_OPEN_FAILED: uvc_open " + libs.err(r)); goto done; }
 ALOGI("uvc_scan_control result=ok bNumInterfaces=see libuvc/device logs");
 r = -1;
 for(const auto& req: requests){
  if((req.fmt == UVC_FRAME_FORMAT_MJPEG || req.fmt == UVC_FRAME_FORMAT_COMPRESSED) && !mjpegAvailable){ ALOGI("skipping format=%s width=%d height=%d fps=%d reason=turbojpeg unavailable", req.name, req.w, req.h, req.fps); continue; }
  r=libs.uvc_get_stream_ctrl_format_size(handle,ctrl,req.fmt,req.w,req.h,req.fps);
  ALOGI("requested format=%s width=%d height=%d fps=%d get_ctrl result=%d", req.name, req.w, req.h, req.fps, r);
  if(r>=0){ selectedFormat=req.fmt; selectedWidth=req.w; selectedHeight=req.h; selectedFps=req.fps; ALOGI("selected format=%s width=%d height=%d fps=%d", req.name, req.w, req.h, req.fps); break; }
 }
 if(r<0){ setError("NATIVE_UVC_STREAM_START_FAILED: uvc_get_stream_ctrl_format_size " + libs.err(r)); goto done; }
 setSelectedMode(selectedFormat, selectedWidth, selectedHeight, selectedFps);
 g_accept_frames=true;
 g_active_callback_state.store(cbState);
 r=libs.uvc_start_streaming(handle,ctrl,cb,cbState,0);
 ALOGI("uvc_start_streaming result=%d error=%s generation=%lld", r, r < 0 ? libs.err(r).c_str() : "none", (long long)generation);
 if(r<0){ g_accept_frames=false; g_active_callback_state.store(nullptr); setSelectedMode(UVC_FRAME_FORMAT_UNKNOWN, 0, 0, 0); setError("NATIVE_UVC_STREAM_START_FAILED: uvc_start_streaming " + libs.err(r)); goto done; }
 streamingStarted=true; clearError(); g_preview=true;
 streamStartNs = nowNs();
 ALOGI("uvc_start_streaming ok generation=%lld", (long long)generation);
 ALOGI("real libuvc stream start succeeded selected format=%s width=%d height=%d fps=%d", frameFormatName(selectedFormat), selectedWidth, selectedHeight, selectedFps);
 while(!g_stop.load() && generation == g_session_generation.load()) {
  std::this_thread::sleep_for(std::chrono::milliseconds(50));
  int64_t now = nowNs();
  int64_t first = g_first_frame_ns.load();
  if(first <= 0) {
   int64_t noFirstFrameAgeMs = (now - streamStartNs) / 1000000LL;
   if(noFirstFrameAgeMs > 3000) {
    ALOGE("native UVC watchdog detected stalled stream generation=%lld", (long long)generation);
    setError("NATIVE_UVC_STREAM_STALLED: no first frame for 3000ms");
    break;
   }
   continue;
  }
  int64_t last = g_last_frame_ns.load();
  if(last > 0) {
   int64_t ageMs = (now - last) / 1000000LL;
   if(ageMs > 2000) {
    ALOGE("native UVC watchdog detected stalled stream generation=%lld", (long long)generation);
    setError("NATIVE_UVC_STREAM_STALLED: no frames for 2000ms");
    break;
   }
  }
 }
done:
 g_accept_frames=false;
 g_active_callback_state.store(nullptr);
 if(streamingStarted && handle){ ALOGI("uvc_stop_streaming start generation=%lld", (long long)generation); libs.uvc_stop_streaming(handle); ALOGI("uvc_stop_streaming done generation=%lld", (long long)generation); std::this_thread::sleep_for(std::chrono::milliseconds(200)); }
 for(int i=0;i<100 && g_callbacks_in_flight.load()>0;i++) std::this_thread::sleep_for(std::chrono::milliseconds(10));
 if(handle){ libs.uvc_close(handle); ALOGI("uvc_close done generation=%lld", (long long)generation); }
 if(dev && libs.uvc_unref_device) libs.uvc_unref_device(dev);
 if(ctx && libs.uvc_exit){ libs.uvc_exit(ctx); ALOGI("uvc_exit done generation=%lld", (long long)generation); }
 free(ctrl);
 delete cbState;
 cbState=nullptr;
 g_preview=false;
 ALOGI("streamingThread stop generation=%lld", (long long)generation);
}
}

extern "C" JNIEXPORT jboolean JNICALL Java_com_maklertour_data_phonecamera_NativeLibuvcCam1Backend_nativeOpen(JNIEnv* env,jobject,jint fd,jint vendorId,jint productId,jstring deviceName,jint busNum,jint devAddr,jstring usbfs,jobject surface,jstring preferredFormat,jint preferredWidth,jint preferredHeight,jint preferredFps,jboolean preferredAuto){
 std::lock_guard<std::mutex> lifecycle(g_lifecycle_lock);
 clearError();
 if(g_thread.joinable()){ ALOGI("stopping previous stream generation=%lld", (long long)g_session_generation.load()); requestStreamStopLocked(); g_thread.join(); ALOGI("previous stream joined"); }
 const char* n=env->GetStringUTFChars(deviceName,nullptr); std::string name=n?n:""; env->ReleaseStringUTFChars(deviceName,n);
 const char* u=env->GetStringUTFChars(usbfs,nullptr); std::string usbfsPath=u?u:""; env->ReleaseStringUTFChars(usbfs,u);
 const char* pf=preferredFormat ? env->GetStringUTFChars(preferredFormat,nullptr) : nullptr; std::string preferred=pf?pf:""; if(preferredFormat) env->ReleaseStringUTFChars(preferredFormat,pf);
 stopRenderThreadLocked();
 { std::lock_guard<std::mutex> lk(g_window_lock); releaseWindowLocked(); if(surface) { g_window=ANativeWindow_fromSurface(env,surface); if(g_window) ANativeWindow_setBuffersGeometry(g_window, 0, 0, WINDOW_FORMAT_RGBA_8888); } }
 { std::lock_guard<std::mutex> lk(g_frame_lock); g_latest_frame.clear(); g_frame_dirty=false; }
 ensureRenderThreadLocked();
 g_received=0; g_decoded=0; g_rendered=0; g_last_frame_ns=0; g_first_frame_ns=0; g_recorded=0; g_latest_sequence=0; g_render_errors=0; g_window_lock_failures=0; g_surface_null_count=0; g_callback_dropped_after_stop=0;
 setSelectedMode(UVC_FRAME_FORMAT_UNKNOWN,0,0,0);
 g_fd=fd; g_vendor=vendorId; g_product=productId; g_device_name=name; g_bus_num=busNum; g_dev_addr=devAddr; g_usbfs=usbfsPath; { std::lock_guard<std::mutex> lk(g_state_lock); g_preferred_format_name=preferred; g_preferred_width=preferredWidth; g_preferred_height=preferredHeight; g_preferred_fps=preferredFps; g_preferred_auto=preferredAuto; } g_opened=true; g_start_ns=nowNs();
 g_stop=false; g_accept_frames=false; g_render_stop=false;
 ALOGI("native UVC opened, waiting for frames fd=%d vendor=%d product=%d current_device=%s usbfs=%s busNum=%d devAddr=%d surfacePresent=%s preferredFormat=%s preferredWidth=%d preferredHeight=%d preferredFps=%d preferredAuto=%s",fd,vendorId,productId,name.c_str(),usbfsPath.c_str(),busNum,devAddr,surface ? "true" : "false", preferred.c_str(), preferredWidth, preferredHeight, preferredFps, preferredAuto ? "true" : "false");
 return JNI_TRUE;
}

static jboolean startPreviewLocked(){
 clearError(); if(!g_opened) return JNI_FALSE;
 if(g_thread.joinable()){
  ALOGI("stopping previous stream generation=%lld", (long long)g_session_generation.load());
  g_lifecycle_restarts++; requestStreamStopLocked(); g_thread.join(); ALOGI("previous stream joined");
 }
 g_stop=false;
 g_first_frame_ns=0;
 g_last_frame_ns=0;
 {
  std::lock_guard<std::mutex> lk(g_frame_lock);
  g_latest_frame.clear();
  g_latest_width=0;
  g_latest_height=0;
  g_latest_format=UVC_FRAME_FORMAT_UNKNOWN;
  g_latest_timestamp_ns=0;
  g_latest_frame_sequence=0;
  g_latest_sequence=0;
  g_frame_dirty=false;
 }
 int64_t generation=++g_session_generation;
 ALOGI("nativeStartPreview requested generation=%lld", (long long)generation);
 ensureRenderThreadLocked();
 g_thread=std::thread(streamingThread, generation, g_fd, g_vendor, g_product, g_device_name, g_bus_num, g_dev_addr, g_usbfs);
 ALOGI("native UVC stream start posted on worker thread generation=%lld", (long long)generation);
 return JNI_TRUE;
}

extern "C" JNIEXPORT jboolean JNICALL Java_com_maklertour_data_phonecamera_NativeLibuvcCam1Backend_nativeStartPreview(JNIEnv*,jobject){
 std::lock_guard<std::mutex> lifecycle(g_lifecycle_lock); return startPreviewLocked();
}

extern "C" JNIEXPORT jboolean JNICALL Java_com_maklertour_data_phonecamera_NativeLibuvcCam1Backend_nativeStartPreviewWithDevice(JNIEnv* env,jobject,jint fd,jint vendor,jint product,jstring deviceName){
 std::lock_guard<std::mutex> lifecycle(g_lifecycle_lock);
 clearError();
 const char* n=env->GetStringUTFChars(deviceName,nullptr); std::string name=n?n:""; env->ReleaseStringUTFChars(deviceName,n);
 if(g_bus_num < 0 || g_dev_addr < 0 || g_usbfs.empty()){
  setError("NATIVE_UVC_OPEN_FAILED: nativeStartPreviewWithDevice requires prior nativeOpen with parsed USB path"); return JNI_FALSE;
 }
 g_fd=fd; g_vendor=vendor; g_product=product; g_device_name=name; if(!g_opened) g_opened=true;
 ALOGI("nativeStartPreviewWithDevice requested fd=%d vendor=%d product=%d deviceName=%s usbfs=%s busNum=%d devAddr=%d", fd, vendor, product, name.c_str(), g_usbfs.c_str(), g_bus_num, g_dev_addr);
 return startPreviewLocked();
}

extern "C" JNIEXPORT void JNICALL Java_com_maklertour_data_phonecamera_NativeLibuvcCam1Backend_nativeStopPreview(JNIEnv*,jobject){
 std::lock_guard<std::mutex> lifecycle(g_lifecycle_lock);
 if(g_thread.joinable()){ ALOGI("stopping previous stream generation=%lld", (long long)g_session_generation.load()); requestStreamStopLocked(); g_thread.join(); ALOGI("previous stream joined"); }
 g_preview=false; g_accept_frames=false; stopRenderThreadLocked();
}
extern "C" JNIEXPORT jboolean JNICALL Java_com_maklertour_data_phonecamera_NativeLibuvcCam1Backend_nativeStartRecording(JNIEnv* env,jobject,jstring path){
 const char* p=env->GetStringUTFChars(path,nullptr);
 std::string out=p?p:"";
 ALOGI("native UVC recording requested path=%s", out.c_str());
 env->ReleaseStringUTFChars(path,p);
 clearError();
 g_recorded=0;
 if(!g_opened.load()){ setError("NATIVE_UVC_RECORD_START_FAILED: camera is not open"); return JNI_FALSE; }
 int fmt=g_selected_format.load();
 if(fmt != UVC_FRAME_FORMAT_MJPEG && fmt != UVC_FRAME_FORMAT_COMPRESSED){ setError(std::string("cam1 raw ") + frameFormatName(fmt) + " recording not implemented"); return JNI_FALSE; }
 if(out.empty()){ setError("NATIVE_UVC_RECORD_START_FAILED: empty output path"); return JNI_FALSE; }
 std::lock_guard<std::mutex> recordGuard(g_record_lock);
 if(g_record_file){ fclose(g_record_file); g_record_file=nullptr; }
 g_record_file=fopen(out.c_str(), "wb");
 if(!g_record_file){ setError(std::string("NATIVE_UVC_RECORD_START_FAILED: fopen failed errno=") + std::to_string(errno)); return JNI_FALSE; }
 g_recording=true;
 return JNI_TRUE;
}
extern "C" JNIEXPORT void JNICALL Java_com_maklertour_data_phonecamera_NativeLibuvcCam1Backend_nativeStopRecording(JNIEnv*,jobject){
 g_recording=false;
 std::lock_guard<std::mutex> recordGuard(g_record_lock);
 if(g_record_file){ fflush(g_record_file); fclose(g_record_file); g_record_file=nullptr; }
}
extern "C" JNIEXPORT void JNICALL Java_com_maklertour_data_phonecamera_NativeLibuvcCam1Backend_nativeClose(JNIEnv*,jobject){
 std::lock_guard<std::mutex> lifecycle(g_lifecycle_lock);
 if(g_thread.joinable()){ ALOGI("stopping previous stream generation=%lld", (long long)g_session_generation.load()); requestStreamStopLocked(); g_thread.join(); ALOGI("previous stream joined"); }
 g_recording=false; { std::lock_guard<std::mutex> recordGuard(g_record_lock); if(g_record_file){ fflush(g_record_file); fclose(g_record_file); g_record_file=nullptr; } } g_opened=false; g_preview=false; g_accept_frames=false; stopRenderThreadLocked(); setSelectedMode(UVC_FRAME_FORMAT_UNKNOWN,0,0,0);
 { std::lock_guard<std::mutex> lk(g_window_lock); releaseWindowLocked(); }
}
extern "C" JNIEXPORT jlongArray JNICALL Java_com_maklertour_data_phonecamera_NativeLibuvcCam1Backend_nativeSnapshot(JNIEnv* env,jobject){ double fps=0.0; auto last=g_last_frame_ns.load(); auto first=g_first_frame_ns.load(); auto rec=g_received.load(); if(first>0&&last>first) fps=(double)(rec-1)*1e9/(double)(last-first); int64_t fpsBits; memcpy(&fpsBits,&fps,8); jlong values[12]={g_received.load(),g_decoded.load(),g_rendered.load(),fpsBits,last,first,g_recorded.load(),g_preview.load()?1:0,g_selected_format.load(),g_selected_width.load(),g_selected_height.load(),g_selected_fps.load()}; jlongArray out=env->NewLongArray(12); env->SetLongArrayRegion(out,0,12,values); return out; }
extern "C" JNIEXPORT jstring JNICALL Java_com_maklertour_data_phonecamera_NativeLibuvcCam1Backend_nativeSelectedFormatName(JNIEnv* env,jobject){ std::lock_guard<std::mutex> lk(g_state_lock); return env->NewStringUTF(g_selected_format_name.c_str()); }
extern "C" JNIEXPORT jstring JNICALL Java_com_maklertour_data_phonecamera_NativeLibuvcCam1Backend_nativeLastError(JNIEnv* env,jobject){ std::lock_guard<std::mutex> lk(g_state_lock); return env->NewStringUTF(g_error.c_str()); }

extern "C" JNIEXPORT jobjectArray JNICALL Java_com_maklertour_data_phonecamera_NativeLibuvcCam1Backend_nativeLatestFrameSnapshot(JNIEnv* env,jobject){
 std::lock_guard<std::mutex> lk(g_frame_lock);
 if(g_latest_frame.empty() || g_latest_timestamp_ns <= 0 || g_latest_frame_sequence <= 0) return nullptr;
 jclass objectClass=env->FindClass("java/lang/Object");
 if(!objectClass) return nullptr;
 jobjectArray out=env->NewObjectArray(2, objectClass, nullptr);
 if(!out) return nullptr;
 jbyteArray bytes=env->NewByteArray((jsize)g_latest_frame.size());
 if(!bytes) return nullptr;
 env->SetByteArrayRegion(bytes,0,(jsize)g_latest_frame.size(),reinterpret_cast<const jbyte*>(g_latest_frame.data()));
 jlong values[5]={g_latest_timestamp_ns,g_latest_frame_sequence,(jlong)g_latest_width,(jlong)g_latest_height,(jlong)g_latest_format};
 jlongArray metadata=env->NewLongArray(5);
 if(!metadata) return nullptr;
 env->SetLongArrayRegion(metadata,0,5,values);
 env->SetObjectArrayElement(out,0,bytes);
 env->SetObjectArrayElement(out,1,metadata);
 env->DeleteLocalRef(bytes);
 env->DeleteLocalRef(metadata);
 env->DeleteLocalRef(objectClass);
 return out;
}
