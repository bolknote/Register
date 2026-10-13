/*
 * HDR-aware resize and JPEG quality selection for the public editor.
 *
 * Copyright 2026 Register contributors
 * SPDX-License-Identifier: MIT
 */

#include <algorithm>
#include <array>
#include <cmath>
#include <cstdint>
#include <cstdlib>
#include <cstring>
#include <memory>
#include <stdexcept>
#include <string>
#include <vector>

#include <ultrahdr_api.h>

namespace {
constexpr uint64_t max_decoded_pixels = 24000000;
constexpr uint64_t max_output_pixels = 8000000;
constexpr int max_edge = 32767;
std::string last_error;

using Decoder = std::unique_ptr<uhdr_codec_private_t, decltype(&uhdr_release_decoder)>;
using Encoder = std::unique_ptr<uhdr_codec_private_t, decltype(&uhdr_release_encoder)>;

void check(uhdr_error_info_t status) {
    if (status.error_code != UHDR_CODEC_OK) {
        throw std::runtime_error(status.has_detail ? status.detail : "HDR codec failed.");
    }
}

void dimensions(int width, int height, uint64_t limit) {
    if (width <= 0 || height <= 0 || width > max_edge || height > max_edge
        || uint64_t(width) * height > limit) {
        throw std::runtime_error("The HDR image dimensions are too large to optimize safely.");
    }
}

Decoder decoder(const uint8_t* data, size_t size, bool hdr) {
    Decoder result(uhdr_create_decoder(), uhdr_release_decoder);
    if (!result) throw std::runtime_error("Unable to allocate the HDR decoder.");
    uhdr_compressed_image_t image{const_cast<uint8_t*>(data), size, size,
        UHDR_CG_UNSPECIFIED, UHDR_CT_UNSPECIFIED, UHDR_CR_UNSPECIFIED};
    check(uhdr_dec_set_image(result.get(), &image));
    check(uhdr_dec_set_out_img_format(result.get(),
        hdr ? UHDR_IMG_FMT_64bppRGBAHalfFloat : UHDR_IMG_FMT_32bppRGBA8888));
    check(uhdr_dec_set_out_color_transfer(result.get(), hdr ? UHDR_CT_LINEAR : UHDR_CT_SRGB));
    check(uhdr_dec_probe(result.get()));
    dimensions(uhdr_dec_get_image_width(result.get()), uhdr_dec_get_image_height(result.get()), max_decoded_pixels);
    dimensions(uhdr_dec_get_gainmap_width(result.get()), uhdr_dec_get_gainmap_height(result.get()), max_decoded_pixels);
    return result;
}

uhdr_raw_image_t* decoded_image(Decoder& context) {
    auto image = uhdr_get_decoded_image(context.get());
    if (image && image->cg == UHDR_CG_UNSPECIFIED) {
        const auto icc = uhdr_dec_get_icc(context.get());
        if (!icc || !icc->data_sz) image->cg = UHDR_CG_BT_709;
    }
    return image;
}

float half_to_float(uint16_t value) {
    const float sign = value & 0x8000 ? -1.0f : 1.0f;
    const int exponent = (value >> 10) & 31;
    const int fraction = value & 1023;
    if (exponent == 31) throw std::runtime_error("The HDR pixels contain a non-finite value.");
    return sign * std::ldexp(float(exponent ? fraction + 1024 : fraction), exponent ? exponent - 25 : -24);
}

uint16_t float_to_half(float value) {
    // HDR output is positive, bounded by the format's 10000/203-nit nominal range.
    value = std::clamp(value, 0.0f, 10000.0f / 203.0f);
    uint32_t bits;
    std::memcpy(&bits, &value, sizeof bits);
    const int exponent = int((bits >> 23) & 255) - 127 + 15;
    if (exponent <= 0) {
        if (exponent < -10) return 0;
        const uint32_t significand = (bits & 0x7fffff) | 0x800000;
        const int shift = 14 - exponent;
        return uint16_t((significand + (1u << (shift - 1))) >> shift);
    }
    return uint16_t((uint32_t(exponent) << 10) + ((bits & 0x7fffff) + 4096) / 8192);
}

float to_linear(float value) {
    return value <= 0.04045f ? value / 12.92f : std::pow((value + 0.055f) / 1.055f, 2.4f);
}

float to_gamma(float value) {
    value = std::max(0.0f, value);
    return value <= 0.0031308f ? 12.92f * value : 1.055f * std::pow(value, 1.0f / 2.4f) - 0.055f;
}

struct Tap { int index; float weight; };
using Filter = std::vector<std::vector<Tap>>;

Filter filter(int source, int target) {
    Filter result(target);
    const double scale = double(source) / target;
    const double support = std::max(1.0, scale);
    const double pi = std::acos(-1.0);
    for (int x = 0; x < target; ++x) {
        const double center = (x + 0.5) * scale - 0.5;
        double sum = 0;
        for (int index = int(std::ceil(center - 3 * support)); index <= int(std::floor(center + 3 * support)); ++index) {
            const double distance = (center - index) / support;
            const double weight = std::abs(distance) < 1e-8 ? 1.0
                : std::abs(distance) >= 3 ? 0.0
                : (std::sin(pi * distance) * std::sin(pi * distance / 3)) / (pi * pi * distance * distance / 3);
            result[x].push_back({std::clamp(index, 0, source - 1), float(weight)});
            sum += weight;
        }
        for (auto& tap : result[x]) tap.weight /= float(sum);
    }
    return result;
}

struct Pixels {
    int width;
    int height;
    uhdr_color_gamut_t gamut;
    std::vector<float> linear;
};

Pixels resize(uhdr_raw_image_t* image, int orientation, int width, int height) {
    if (!image || (image->fmt != UHDR_IMG_FMT_64bppRGBAHalfFloat && image->fmt != UHDR_IMG_FMT_32bppRGBA8888)
        || image->cg == UHDR_CG_UNSPECIFIED) {
        throw std::runtime_error("The HDR image color profile is unsupported.");
    }
    static const std::array<float, 256> srgb = [] {
        std::array<float, 256> values{};
        for (size_t i = 0; i < values.size(); ++i) values[i] = to_linear(i / 255.0f);
        return values;
    }();
    const int source_width = orientation >= 5 ? image->h : image->w;
    const int source_height = orientation >= 5 ? image->w : image->h;
    auto pixel = [&](int x, int y, int channel) {
        int sx = x, sy = y;
        switch (orientation) {
            case 2: sx = image->w - 1 - x; break;
            case 3: sx = image->w - 1 - x; sy = image->h - 1 - y; break;
            case 4: sy = image->h - 1 - y; break;
            case 5: sx = y; sy = x; break;
            case 6: sx = y; sy = image->h - 1 - x; break;
            case 7: sx = image->w - 1 - y; sy = image->h - 1 - x; break;
            case 8: sx = image->w - 1 - y; sy = x; break;
        }
        const size_t offset = (size_t(sy) * image->stride[0] + sx) * 4 + channel;
        return image->fmt == UHDR_IMG_FMT_64bppRGBAHalfFloat
            ? half_to_float(static_cast<uint16_t*>(image->planes[0])[offset])
            : srgb[static_cast<uint8_t*>(image->planes[0])[offset]];
    };
    Pixels result{width, height, image->cg, std::vector<float>(size_t(width) * height * 3)};
    if (source_width == width && source_height == height) {
        for (int y = 0; y < height; ++y) for (int x = 0; x < width; ++x) for (int c = 0; c < 3; ++c) {
            result.linear[(size_t(y) * width + x) * 3 + c] = pixel(x, y, c);
        }
        return result;
    }
    const Filter horizontal = filter(source_width, width), vertical = filter(source_height, height);
    std::vector<float> intermediate(size_t(width) * source_height * 3);
    for (int y = 0; y < source_height; ++y) for (int x = 0; x < width; ++x) for (int c = 0; c < 3; ++c) {
        float value = 0;
        for (const auto& tap : horizontal[x]) value += pixel(tap.index, y, c) * tap.weight;
        intermediate[(size_t(y) * width + x) * 3 + c] = value;
    }
    for (int y = 0; y < height; ++y) for (int x = 0; x < width; ++x) for (int c = 0; c < 3; ++c) {
        float value = 0;
        for (const auto& tap : vertical[y]) value += intermediate[(size_t(tap.index) * width + x) * 3 + c] * tap.weight;
        result.linear[(size_t(y) * width + x) * 3 + c] = std::max(0.0f, value);
    }
    return result;
}

struct Raw {
    std::vector<uint8_t> bytes;
    uhdr_raw_image_t image{};
    Raw(const Pixels& pixels, bool hdr) : bytes(size_t(pixels.width) * pixels.height * (hdr ? 8 : 4)) {
        image.fmt = hdr ? UHDR_IMG_FMT_64bppRGBAHalfFloat : UHDR_IMG_FMT_32bppRGBA8888;
        image.cg = pixels.gamut;
        image.ct = hdr ? UHDR_CT_LINEAR : UHDR_CT_SRGB;
        image.range = UHDR_CR_FULL_RANGE;
        image.w = pixels.width;
        image.h = pixels.height;
        image.planes[0] = bytes.data();
        image.stride[0] = pixels.width;
        for (size_t index = 0; index < size_t(pixels.width) * pixels.height; ++index) {
            for (int c = 0; c < 3; ++c) {
                if (hdr) reinterpret_cast<uint16_t*>(bytes.data())[index * 4 + c] = float_to_half(pixels.linear[index * 3 + c]);
                else bytes[index * 4 + c] = uint8_t(std::round(std::clamp(to_gamma(pixels.linear[index * 3 + c]), 0.0f, 1.0f) * 255));
            }
            if (hdr) reinterpret_cast<uint16_t*>(bytes.data())[index * 4 + 3] = 0x3c00;
            else bytes[index * 4 + 3] = 255;
        }
    }
};

std::vector<uint8_t> encode(Raw& sdr, Raw& hdr, int quality, int gainmap_scale, const uhdr_gainmap_metadata_t& source) {
    Encoder encoder(uhdr_create_encoder(), uhdr_release_encoder);
    if (!encoder) throw std::runtime_error("Unable to allocate the HDR encoder.");
    check(uhdr_enc_set_raw_image(encoder.get(), &sdr.image, UHDR_SDR_IMG));
    check(uhdr_enc_set_raw_image(encoder.get(), &hdr.image, UHDR_HDR_IMG));
    check(uhdr_enc_set_quality(encoder.get(), quality, UHDR_BASE_IMG));
    check(uhdr_enc_set_quality(encoder.get(), quality, UHDR_GAIN_MAP_IMG));
    check(uhdr_enc_set_gainmap_scale_factor(encoder.get(), gainmap_scale));
    check(uhdr_enc_set_using_multi_channel_gainmap(encoder.get(), 1));
    check(uhdr_enc_set_preset(encoder.get(), UHDR_USAGE_BEST_QUALITY));
    check(uhdr_enc_set_target_display_peak_brightness(encoder.get(), std::clamp(source.hdr_capacity_max * 203, 203.0f, 10000.0f)));
    check(uhdr_encode(encoder.get()));
    const auto output = uhdr_get_encoded_stream(encoder.get());
    if (!output || !output->data || !output->data_sz) throw std::runtime_error("The HDR encoder returned no image.");
    std::vector<uint8_t> result(static_cast<uint8_t*>(output->data), static_cast<uint8_t*>(output->data) + output->data_sz);
    // Keep the source display transition, including sources whose gain map starts above SDR white.
    Decoder probe = decoder(result.data(), result.size(), true);
    auto metadata = *uhdr_dec_get_gainmap_metadata(probe.get());
    metadata.hdr_capacity_min = source.hdr_capacity_min;
    metadata.hdr_capacity_max = source.hdr_capacity_max;
    Encoder muxer(uhdr_create_encoder(), uhdr_release_encoder);
    if (!muxer) throw std::runtime_error("Unable to allocate the HDR container encoder.");
    const auto base_block = uhdr_dec_get_base_image(probe.get());
    const auto gain_block = uhdr_dec_get_gainmap_image(probe.get());
    uhdr_compressed_image_t base{base_block->data, base_block->data_sz, base_block->capacity,
        sdr.image.cg, UHDR_CT_SRGB, UHDR_CR_FULL_RANGE};
    uhdr_compressed_image_t gain{gain_block->data, gain_block->data_sz, gain_block->capacity,
        hdr.image.cg, UHDR_CT_UNSPECIFIED, UHDR_CR_UNSPECIFIED};
    check(uhdr_enc_set_compressed_image(muxer.get(), &base, UHDR_BASE_IMG));
    check(uhdr_enc_set_gainmap_image(muxer.get(), &gain, &metadata));
    check(uhdr_encode(muxer.get()));
    const auto packed = uhdr_get_encoded_stream(muxer.get());
    if (!packed || !packed->data || !packed->data_sz) throw std::runtime_error("The HDR container encoder returned no image.");
    return {static_cast<uint8_t*>(packed->data), static_cast<uint8_t*>(packed->data) + packed->data_sz};
}

// Compare both display renditions in 8x8 luma windows. HDR is scaled by the source
// headroom before applying the sRGB transfer; it is never clipped to SDR white.
float score(const Pixels& reference, const Pixels& candidate, float headroom) {
    if (reference.gamut != candidate.gamut) throw std::runtime_error("The HDR candidate changed its color gamut.");
    double total = 0;
    size_t windows = 0;
    const float red = reference.gamut == UHDR_CG_DISPLAY_P3 ? 0.229f : reference.gamut == UHDR_CG_BT_2100 ? 0.2627f : 0.2126f;
    const float green = reference.gamut == UHDR_CG_DISPLAY_P3 ? 0.6917f : reference.gamut == UHDR_CG_BT_2100 ? 0.678f : 0.7152f;
    auto luma = [&](const Pixels& pixels, int x, int y) {
        const size_t i = (size_t(y) * pixels.width + x) * 3;
        return red * to_gamma(pixels.linear[i] / headroom) + green * to_gamma(pixels.linear[i + 1] / headroom)
            + (1 - red - green) * to_gamma(pixels.linear[i + 2] / headroom);
    };
    for (int top = 0; top < reference.height; top += 8) for (int left = 0; left < reference.width; left += 8) {
        double sum_a = 0, sum_b = 0, squares_a = 0, squares_b = 0, product = 0;
        int count = 0;
        for (int y = top; y < std::min(top + 8, reference.height); ++y) for (int x = left; x < std::min(left + 8, reference.width); ++x) {
            const double a = luma(reference, x, y), b = luma(candidate, x, y);
            sum_a += a; sum_b += b; squares_a += a * a; squares_b += b * b; product += a * b; ++count;
        }
        const double mean_a = sum_a / count, mean_b = sum_b / count;
        const double variance_a = count > 1 ? (squares_a - sum_a * mean_a) / (count - 1) : 0;
        const double variance_b = count > 1 ? (squares_b - sum_b * mean_b) / (count - 1) : 0;
        const double covariance = count > 1 ? (product - sum_a * mean_b) / (count - 1) : 0;
        total += ((2 * mean_a * mean_b + 0.0001) * (2 * covariance + 0.0009))
            / ((mean_a * mean_a + mean_b * mean_b + 0.0001) * (variance_a + variance_b + 0.0009));
        ++windows;
    }
    return float(total / windows);
}

float candidate_score(const Pixels& sdr, const Pixels& hdr, const std::vector<uint8_t>& candidate, float headroom) {
    float result = 1;
    for (const bool is_hdr : {false, true}) {
        Decoder decoded = decoder(candidate.data(), candidate.size(), is_hdr);
        check(uhdr_decode(decoded.get()));
        Pixels pixels = resize(decoded_image(decoded), 1, sdr.width, sdr.height);
        result = std::min(result, score(is_hdr ? hdr : sdr, pixels, is_hdr ? headroom : 1));
    }
    return result;
}
}

extern "C" {
uint8_t* register_hdr_optimize(const uint8_t* data, size_t size, int orientation, int width, int height,
    int min_quality, int max_quality, float min_ssim, int search_steps, size_t* output_size, float* output_score, int* output_quality) {
    last_error.clear();
    if (!output_size || !output_score || !output_quality) return nullptr;
    *output_size = 0; *output_score = 0; *output_quality = 0;
    try {
        if (!data || !size || orientation < 1 || orientation > 8 || min_quality < 0 || max_quality > 100
            || min_quality > max_quality || !std::isfinite(min_ssim) || min_ssim <= 0 || min_ssim > 1
            || search_steps < 0 || search_steps > 10) throw std::runtime_error("Invalid HDR optimizer input.");
        dimensions(width, height, max_output_pixels);
        uhdr_gainmap_metadata_t metadata{};
        int gainmap_scale = 1;
        bool can_keep_source = false;
        Pixels sdr, hdr;
        for (const bool is_hdr : {false, true}) {
            Decoder input = decoder(data, size, is_hdr);
            const int source_width = orientation >= 5 ? uhdr_dec_get_image_height(input.get()) : uhdr_dec_get_image_width(input.get());
            const int source_height = orientation >= 5 ? uhdr_dec_get_image_width(input.get()) : uhdr_dec_get_image_height(input.get());
            if (width > source_width || height > source_height) throw std::runtime_error("The HDR image must not be enlarged.");
            can_keep_source = orientation == 1 && width == source_width && height == source_height;
            metadata = *uhdr_dec_get_gainmap_metadata(input.get());
            gainmap_scale = std::clamp(int(std::round(std::min(
                float(uhdr_dec_get_image_width(input.get())) / uhdr_dec_get_gainmap_width(input.get()),
                float(uhdr_dec_get_image_height(input.get())) / uhdr_dec_get_gainmap_height(input.get())))), 1, 128);
            check(uhdr_decode(input.get()));
            (is_hdr ? hdr : sdr) = resize(decoded_image(input), orientation, width, height);
        }
        Raw sdr_raw(sdr, false), hdr_raw(hdr, true);
        std::vector<uint8_t> best;
        float best_score = 0;
        int best_quality = 0;
        auto probe = [&](int quality) {
            auto encoded = encode(sdr_raw, hdr_raw, quality, gainmap_scale, metadata);
            const float ssim = candidate_score(sdr, hdr, encoded, metadata.hdr_capacity_max);
            if (ssim >= min_ssim && (best.empty() || encoded.size() < best.size())) {
                best = std::move(encoded); best_score = ssim; best_quality = quality;
            }
            return ssim >= min_ssim;
        };
        int low = min_quality, high = max_quality;
        while (!probe(high) && gainmap_scale > 1) gainmap_scale = std::max(1, gainmap_scale / 2);
        if (best.empty()) {
            if (!can_keep_source) throw std::runtime_error("HDR compression could not meet the image quality threshold.");
            // Both source renditions have been decoded and validated above. The
            // caller supplies a sanitized container; retaining it is lossless.
            // Resized and oriented uploads still require normalized output pixels.
            best.assign(data, data + size);
            best_score = 1;
        } else if (low < high && !probe(low)) {
            for (int step = 0; step < search_steps && high - low > 1; ++step) {
                const int middle = (low + high) / 2;
                if (probe(middle)) high = middle;
                else low = middle;
            }
        }
        auto output = static_cast<uint8_t*>(std::malloc(best.size()));
        if (!output) throw std::runtime_error("Unable to allocate the optimized HDR image.");
        std::memcpy(output, best.data(), best.size());
        *output_size = best.size(); *output_score = best_score; *output_quality = best_quality;
        return output;
    } catch (const std::exception& error) {
        last_error = error.what();
        return nullptr;
    }
}

const char* register_hdr_error() { return last_error.c_str(); }
}
