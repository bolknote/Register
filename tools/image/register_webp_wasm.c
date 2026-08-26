/*
 * Thin browser-facing wrapper around libwebp's advanced encoder API.
 *
 * Copyright 2026 Roman Parpalak
 * SPDX-License-Identifier: MIT
 */

#include <stddef.h>
#include <stdint.h>

#include <emscripten/emscripten.h>
#include <webp/encode.h>

EMSCRIPTEN_KEEPALIVE
uint8_t* register_webp_encode(
    const uint8_t* rgba,
    int            width,
    int            height,
    float          quality,
    int            lossless,
    int            method,
    int            alpha_quality,
    int            near_lossless,
    int            use_sharp_yuv,
    int            exact,
    size_t*        output_size
) {
    WebPConfig config;
    WebPPicture picture;
    WebPMemoryWriter writer;

    if (output_size == NULL) {
        return NULL;
    }
    *output_size = 0;
    if (rgba == NULL || width <= 0 || height <= 0 || width > 32767 || height > 32767) {
        return NULL;
    }
    if (!WebPConfigInit(&config) || !WebPPictureInit(&picture)) {
        return NULL;
    }

    config.quality = quality;
    config.lossless = lossless != 0;
    config.method = method;
    config.alpha_quality = alpha_quality;
    config.near_lossless = near_lossless;
    config.use_sharp_yuv = use_sharp_yuv != 0;
    config.exact = exact != 0;
    config.autofilter = 1;
    config.thread_level = 0;
    if (!WebPValidateConfig(&config)) {
        return NULL;
    }

    picture.width = width;
    picture.height = height;
    picture.use_argb = config.lossless;
    if (!WebPPictureImportRGBA(&picture, rgba, width * 4)) {
        WebPPictureFree(&picture);
        return NULL;
    }

    WebPMemoryWriterInit(&writer);
    picture.writer = WebPMemoryWrite;
    picture.custom_ptr = &writer;
    if (!WebPEncode(&config, &picture)) {
        WebPMemoryWriterClear(&writer);
        WebPPictureFree(&picture);
        return NULL;
    }

    WebPPictureFree(&picture);
    *output_size = writer.size;
    return writer.mem;
}

EMSCRIPTEN_KEEPALIVE
void register_webp_free(void* memory)
{
    WebPFree(memory);
}

EMSCRIPTEN_KEEPALIVE
int register_webp_version(void)
{
    return WebPGetEncoderVersion();
}
