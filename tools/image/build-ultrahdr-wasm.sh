#!/usr/bin/env bash

set -euo pipefail

readonly uhdr_version='1.5.0'
readonly uhdr_sha256='2516ea9cb69a0efd42959fad56de760fcb898a4944231f20789f9c5387ca9c81'
readonly jpeg_version='3.1.2'
readonly jpeg_sha256='8f0012234b464ce50890c490f18194f913a7b1f4e6a03d6644179fa0f867d0cf'
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly script_dir
project_dir="$(cd "${script_dir}/../.." && pwd)"
readonly project_dir
work_dir="$(mktemp -d)"
readonly work_dir
readonly output_js="${project_dir}/_assets/register/image-optimizer/lib/register-ultrahdr.js"
readonly uhdr_source="${work_dir}/libultrahdr-${uhdr_version}"
readonly jpeg_source="${work_dir}/libjpeg-turbo-${jpeg_version}"
readonly jpeg_build="${work_dir}/jpeg-build"
readonly uhdr_build="${work_dir}/uhdr-build"
readonly archive_dir="${REGISTER_IMAGE_CODEC_ARCHIVES:-}"
# Keep SDK caches writable even when the SDK itself is installed read-only.
export EM_CACHE="${EM_CACHE:-${work_dir}/emscripten-cache}"

cleanup() {
    rm -rf "${work_dir}"
}
trap cleanup EXIT

for executable in awk cp curl shasum tar emcmake cmake em++; do
    if ! command -v "${executable}" >/dev/null 2>&1; then
        echo "Required executable is missing: ${executable}" >&2
        exit 1
    fi
done

download() {
    if [[ -n "${archive_dir}" && -f "${archive_dir}/$(basename "$2")" ]]; then
        cp "${archive_dir}/$(basename "$2")" "$2"
    else
        curl --fail --location --silent --show-error --connect-timeout 15 --max-time 120 "$1" --output "$2"
    fi
    if [[ "$(shasum -a 256 "$2" | awk '{print $1}')" != "$3" ]]; then
        echo 'Codec source archive checksum mismatch.' >&2
        exit 1
    fi
    tar -xzf "$2" -C "${work_dir}"
}

download "https://codeload.github.com/google/libultrahdr/tar.gz/refs/tags/v${uhdr_version}" \
    "${work_dir}/ultrahdr.tar.gz" "${uhdr_sha256}"
download "https://github.com/libjpeg-turbo/libjpeg-turbo/releases/download/${jpeg_version}/libjpeg-turbo-${jpeg_version}.tar.gz" \
    "${work_dir}/jpeg.tar.gz" "${jpeg_sha256}"

emcmake cmake -S "${jpeg_source}" -B "${jpeg_build}" \
    -DCMAKE_BUILD_TYPE=Release -DBUILD=20250903 -DENABLE_SHARED=OFF \
    -DWITH_SIMD=OFF -DWITH_TURBOJPEG=OFF -DWITH_TOOLS=OFF -DWITH_TESTS=OFF \
    -DCMAKE_C_FLAGS='-O3 -flto'
cmake --build "${jpeg_build}" --target jpeg-static --parallel

emcmake cmake -S "${uhdr_source}" -B "${uhdr_build}" \
    -DCMAKE_BUILD_TYPE=Release -DBUILD_SHARED_LIBS=OFF -DUHDR_BUILD_DEPS=ON \
    -DUHDR_BUILD_EXAMPLES=OFF -DUHDR_ENABLE_INSTALL=OFF -DUHDR_ENABLE_INTRINSICS=OFF \
    -DUHDR_WRITE_XMP=ON -DUHDR_WRITE_ISO=ON -DUHDR_MAX_DIMENSION=32767 \
    -DJPEG_FOUND=ON -DJPEG_LIBRARIES="${jpeg_build}/libjpeg.a" \
    -DJPEG_INCLUDE_DIRS="${jpeg_source}/src;${jpeg_build}" \
    -DCMAKE_C_FLAGS='-O3 -flto' -DCMAKE_CXX_FLAGS='-O3 -flto -fexceptions'
cmake --build "${uhdr_build}" --target uhdr --parallel

em++ "${script_dir}/register_ultrahdr_wasm.cpp" \
    "${uhdr_build}/libuhdr.a" "${jpeg_build}/libjpeg.a" \
    -I"${uhdr_source}" -std=c++17 -O3 -flto -fexceptions \
    -s MODULARIZE=1 -s EXPORT_NAME=RegisterUltraHdrModule -s ENVIRONMENT=worker \
    -s FILESYSTEM=0 -s ALLOW_MEMORY_GROWTH=1 -s MAXIMUM_MEMORY=1073741824 \
    -s ASSERTIONS=0 \
    -s EXPORTED_FUNCTIONS='["_register_hdr_optimize","_register_hdr_error","_malloc","_free"]' \
    -s EXPORTED_RUNTIME_METHODS='["HEAPU8","HEAPU32","HEAPF32","UTF8ToString"]' \
    -o "${output_js}"

cat "${uhdr_source}/LICENSE" "${uhdr_source}/third_party/image_io/LICENSE" \
    "${uhdr_source}/third_party/image_io/src/modp_b64/LICENSE" "${jpeg_source}/LICENSE.md" \
    "${jpeg_source}/README.ijg" > "${output_js%.js}.LICENSE"
chmod 0644 "${output_js}" "${output_js%.js}.wasm" "${output_js%.js}.LICENSE"
shasum -a 256 "${output_js}" "${output_js%.js}.wasm"
