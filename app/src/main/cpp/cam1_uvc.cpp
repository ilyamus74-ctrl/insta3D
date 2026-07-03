#include <jni.h>
#include <android/log.h>
#include <android/native_window_jni.h>
#include <atomic>
#include <cstdint>
#include <cstring>

#define LOG_TAG "Cam1NativeUvc"
#define ALOGI(...) __android_log_print(ANDROID_LOG_INFO, LOG_TAG, __VA_ARGS__)
#define ALOGE(...) __android_log_print(ANDROID_LOG_ERROR, LOG_TAG, __VA_ARGS__)

namespace {
std::atomic<bool> g_opened{false};
std::atomic<bool> g_preview{false};
std::atomic<bool> g_recording{false};
std::atomic<int64_t> g_received{0};
std::atomic<int64_t> g_decoded{0};
std::atomic<int64_t> g_rendered{0};
std::atomic<int64_t> g_last_frame_ns{0};
std::atomic<int64_t> g_first_frame_ns{0};
std::atomic<int64_t> g_recorded{0};
ANativeWindow* g_window = nullptr;

void releaseWindow() {
    if (g_window) {
        ANativeWindow_release(g_window);
        g_window = nullptr;
    }
}
}

extern "C" JNIEXPORT jboolean JNICALL
Java_com_maklertour_data_phonecamera_NativeLibuvcCam1Backend_nativeOpen(
        JNIEnv* env, jobject, jint fd, jint vendorId, jint productId, jstring deviceName, jobject surface) {
    const char* name = env->GetStringUTFChars(deviceName, nullptr);
    ALOGI("native libuvc placeholder open fd=%d vendor=%d product=%d device=%s", fd, vendorId, productId, name ? name : "");
    env->ReleaseStringUTFChars(deviceName, name);
    releaseWindow();
    if (surface) g_window = ANativeWindow_fromSurface(env, surface);
    g_received = 0;
    g_decoded = 0;
    g_rendered = 0;
    g_last_frame_ns = 0;
    g_first_frame_ns = 0;
    g_recorded = 0;
    g_opened = true;
    return JNI_TRUE;
}

extern "C" JNIEXPORT jboolean JNICALL
Java_com_maklertour_data_phonecamera_NativeLibuvcCam1Backend_nativeStartPreview(JNIEnv*, jobject) {
    if (!g_opened.load()) return JNI_FALSE;
    // Real libuvc/libusb streaming is intentionally isolated here. Until the
    // native dependency is linked, this reports opened-with-no-frames rather
    // than faking counters from Java USB Host packets.
    g_preview = true;
    ALOGI("native UVC preview started; awaiting libuvc frame callbacks");
    return JNI_TRUE;
}

extern "C" JNIEXPORT void JNICALL
Java_com_maklertour_data_phonecamera_NativeLibuvcCam1Backend_nativeStopPreview(JNIEnv*, jobject) {
    g_preview = false;
}

extern "C" JNIEXPORT jboolean JNICALL
Java_com_maklertour_data_phonecamera_NativeLibuvcCam1Backend_nativeStartRecording(JNIEnv* env, jobject, jstring path) {
    const char* p = env->GetStringUTFChars(path, nullptr);
    ALOGI("native UVC recording requested path=%s", p ? p : "");
    env->ReleaseStringUTFChars(path, p);
    g_recorded = 0;
    g_recording = g_opened.load();
    return g_recording.load() ? JNI_TRUE : JNI_FALSE;
}

extern "C" JNIEXPORT void JNICALL
Java_com_maklertour_data_phonecamera_NativeLibuvcCam1Backend_nativeStopRecording(JNIEnv*, jobject) {
    g_recording = false;
}

extern "C" JNIEXPORT void JNICALL
Java_com_maklertour_data_phonecamera_NativeLibuvcCam1Backend_nativeClose(JNIEnv*, jobject) {
    g_preview = false;
    g_recording = false;
    g_opened = false;
    releaseWindow();
}

extern "C" JNIEXPORT jlongArray JNICALL
Java_com_maklertour_data_phonecamera_NativeLibuvcCam1Backend_nativeSnapshot(JNIEnv* env, jobject) {
    jlong values[8];
    values[0] = g_received.load();
    values[1] = g_decoded.load();
    values[2] = g_rendered.load();
    double fps = 0.0;
    static_assert(sizeof(double) == sizeof(int64_t));
    int64_t fpsBits;
    memcpy(&fpsBits, &fps, sizeof(double));
    values[3] = fpsBits;
    values[4] = g_last_frame_ns.load();
    values[5] = g_first_frame_ns.load();
    values[6] = g_recorded.load();
    values[7] = g_preview.load() ? 1 : 0;
    jlongArray out = env->NewLongArray(8);
    env->SetLongArrayRegion(out, 0, 8, values);
    return out;
}