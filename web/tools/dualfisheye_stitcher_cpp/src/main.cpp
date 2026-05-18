#include <opencv2/core.hpp>
#include <opencv2/imgcodecs.hpp>
#include <opencv2/imgproc.hpp>

#include <cmath>
#include <filesystem>
#include <iostream>
#include <map>
#include <string>
#include <vector>

namespace {

struct LensProfile { double cx; double cy; double r; };
struct Params {
    std::string input;
    std::string output;
    int width = 4096;
    int height = 2048;
    double fov = 197.0;
    double blendWidth = 22.0;
    double leftYaw = 180.0;
    double rightYaw = 0.0;
    double leftRoll = 0.0;
    double rightRoll = 0.0;
    int jpegQuality = 92;
    bool json = false;
};

static double deg2rad(double d) { return d * CV_PI / 180.0; }

bool parseArgs(int argc, char** argv, Params& p, std::string& err) {
    std::map<std::string, std::string> kv;
    for (int i = 1; i < argc; ++i) {
        std::string a = argv[i];
        if (a == "--json") { p.json = true; continue; }
        if (a.rfind("--", 0) != 0 || i + 1 >= argc) { err = "invalid arguments"; return false; }
        kv[a] = argv[++i];
    }
    auto get = [&](const std::string& k) -> std::string { return kv.count(k) ? kv[k] : ""; };
    p.input = get("--input");
    p.output = get("--output");
    if (p.input.empty() || p.output.empty()) { err = "--input and --output are required"; return false; }
    if (!get("--width").empty()) p.width = std::stoi(get("--width"));
    if (!get("--height").empty()) p.height = std::stoi(get("--height"));
    if (!get("--fov").empty()) p.fov = std::stod(get("--fov"));
    if (!get("--blend-width").empty()) p.blendWidth = std::stod(get("--blend-width"));
    if (!get("--left-yaw").empty()) p.leftYaw = std::stod(get("--left-yaw"));
    if (!get("--right-yaw").empty()) p.rightYaw = std::stod(get("--right-yaw"));
    if (!get("--left-roll").empty()) p.leftRoll = std::stod(get("--left-roll"));
    if (!get("--right-roll").empty()) p.rightRoll = std::stod(get("--right-roll"));
    if (!get("--jpeg-quality").empty()) p.jpegQuality = std::stoi(get("--jpeg-quality"));
    return true;
}

std::string esc(const std::string& s) {
    std::string out;
    for (char c : s) { if (c == '"' || c == '\\') out.push_back('\\'); out.push_back(c); }
    return out;
}

void printError(const std::string& e) { std::cout << "{\"ok\":false,\"error\":\"" << esc(e) << "\"}\n"; }

} // namespace

int main(int argc, char** argv) {
    Params p;
    std::string err;
    if (!parseArgs(argc, argv, p, err)) { printError(err); return 1; }

    cv::Mat src = cv::imread(p.input, cv::IMREAD_COLOR);
    if (src.empty()) { printError("failed_to_read_input"); return 1; }

    LensProfile left{src.cols * 0.25, src.rows * 0.5, src.rows * 0.49};
    LensProfile right{src.cols * 0.75, src.rows * 0.5, src.rows * 0.49};

    cv::Mat mapXL(p.height, p.width, CV_32FC1), mapYL(p.height, p.width, CV_32FC1);
    cv::Mat mapXR(p.height, p.width, CV_32FC1), mapYR(p.height, p.width, CV_32FC1);
    cv::Mat wL(p.height, p.width, CV_32FC1, cv::Scalar(0)), wR(p.height, p.width, CV_32FC1, cv::Scalar(0));

    const double halfFov = deg2rad(p.fov / 2.0);
    const double blendRad = std::max(1e-6, deg2rad(p.blendWidth));
    const double lyaw = deg2rad(p.leftYaw), ryaw = deg2rad(p.rightYaw);
    const double lroll = deg2rad(p.leftRoll), rroll = deg2rad(p.rightRoll);

    auto project = [&](double yaw, double roll, const LensProfile& lens, float& sx, float& sy, float& w, double dx, double dy, double dz) {
        double fx = std::sin(yaw), fz = std::cos(yaw);
        double rx = std::cos(yaw), rz = -std::sin(yaw);
        double lx = dx * rx + dz * rz;
        double ly = dy;
        double lz = dx * fx + dz * fz;
        double alpha = std::atan2(std::sqrt(lx * lx + ly * ly), lz);
        if (alpha > halfFov) { sx = sy = -1.0f; w = 0.0f; return; }
        double beta = std::atan2(ly, lx) + roll;
        double rr = alpha / halfFov * lens.r;
        sx = static_cast<float>(lens.cx + rr * std::cos(beta));
        sy = static_cast<float>(lens.cy - rr * std::sin(beta));
        double m = std::clamp((halfFov - alpha) / blendRad, 0.0, 1.0);
        w = static_cast<float>(m * m);
    };

    for (int y = 0; y < p.height; ++y) {
        double phi = (0.5 - static_cast<double>(y) / p.height) * CV_PI;
        double cphi = std::cos(phi);
        double dy = std::sin(phi);
        for (int x = 0; x < p.width; ++x) {
            double theta = static_cast<double>(x) / p.width * 2.0 * CV_PI - CV_PI;
            double dx = cphi * std::sin(theta);
            double dz = cphi * std::cos(theta);

            float lx, ly, lwt, rx, ry, rwt;
            project(lyaw, lroll, left, lx, ly, lwt, dx, dy, dz);
            project(ryaw, rroll, right, rx, ry, rwt, dx, dy, dz);

            mapXL.at<float>(y, x) = lx; mapYL.at<float>(y, x) = ly; wL.at<float>(y, x) = lwt;
            mapXR.at<float>(y, x) = rx; mapYR.at<float>(y, x) = ry; wR.at<float>(y, x) = rwt;
        }
    }

    cv::Mat leftImg, rightImg;
    cv::remap(src, leftImg, mapXL, mapYL, cv::INTER_LINEAR, cv::BORDER_CONSTANT, cv::Scalar(0, 0, 0));
    cv::remap(src, rightImg, mapXR, mapYR, cv::INTER_LINEAR, cv::BORDER_CONSTANT, cv::Scalar(0, 0, 0));

    cv::Mat out(p.height, p.width, CV_8UC3, cv::Scalar(0, 0, 0));
    for (int y = 0; y < p.height; ++y) {
        for (int x = 0; x < p.width; ++x) {
            float lw = wL.at<float>(y, x), rw = wR.at<float>(y, x);
            float sum = lw + rw;
            if (sum <= 1e-6f) continue;
            lw /= sum; rw /= sum;
            const cv::Vec3b& lc = leftImg.at<cv::Vec3b>(y, x);
            const cv::Vec3b& rc = rightImg.at<cv::Vec3b>(y, x);
            cv::Vec3b& oc = out.at<cv::Vec3b>(y, x);
            for (int c = 0; c < 3; ++c) oc[c] = static_cast<uchar>(lc[c] * lw + rc[c] * rw);
        }
    }

    std::vector<int> params{cv::IMWRITE_JPEG_QUALITY, p.jpegQuality};
    if (!cv::imwrite(p.output, out, params)) { printError("failed_to_write_output"); return 1; }
    std::uintmax_t sz = 0;
    try { sz = std::filesystem::file_size(p.output); } catch (...) {}

    std::cout << "{\"ok\":true,\"input\":\"" << esc(p.input) << "\",\"output\":\"" << esc(p.output)
              << "\",\"source_width\":" << src.cols << ",\"source_height\":" << src.rows
              << ",\"output_width\":" << p.width << ",\"output_height\":" << p.height
              << ",\"profile\":\"insta360_x4_5888x2944_v1\",\"left\":{\"cx\":" << left.cx << ",\"cy\":" << left.cy << ",\"r\":" << left.r
              << "},\"right\":{\"cx\":" << right.cx << ",\"cy\":" << right.cy << ",\"r\":" << right.r
              << "},\"params\":{\"fov\":" << p.fov << ",\"blend_width\":" << p.blendWidth
              << ",\"left_yaw\":" << p.leftYaw << ",\"right_yaw\":" << p.rightYaw
              << ",\"left_roll\":" << p.leftRoll << ",\"right_roll\":" << p.rightRoll
              << ",\"jpeg_quality\":" << p.jpegQuality << "},\"output_size_bytes\":" << sz << "}\n";
    return 0;
}
