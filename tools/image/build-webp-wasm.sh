#!/usr/bin/env bash

set -euo pipefail

readonly libwebp_version='1.6.0'
readonly libwebp_sha256='e4ab7009bf0629fd11982d4c2aa83964cf244cffba7347ecd39019a9e38c4564'
readonly source_url="https://storage.googleapis.com/downloads.webmproject.org/releases/webp/libwebp-${libwebp_version}.tar.gz"
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly script_dir
project_dir="$(cd "${script_dir}/../.." && pwd)"
readonly project_dir
work_dir="$(mktemp -d)"
readonly work_dir
readonly archive_path="${work_dir}/libwebp.tar.gz"
readonly source_dir="${work_dir}/libwebp-${libwebp_version}"
readonly build_dir="${work_dir}/build"
readonly output_js="${project_dir}/_assets/register/image-optimizer/lib/register-webp.js"

cleanup() {
    rm -rf "${work_dir}"
}
trap cleanup EXIT

for executable in awk curl shasum tar emcmake cmake emcc; do
    if ! command -v "${executable}" >/dev/null 2>&1; then
        echo "Required executable is missing: ${executable}" >&2
        exit 1
    fi
done

curl --fail --location --silent --show-error "${source_url}" --output "${archive_path}"
if [[ "$(shasum -a 256 "${archive_path}" | awk '{print $1}')" != "${libwebp_sha256}" ]]; then
    echo 'libwebp archive checksum mismatch.' >&2
    exit 1
fi
tar -xzf "${archive_path}" -C "${work_dir}"

emcmake cmake -S "${source_dir}" -B "${build_dir}" \
    -DCMAKE_BUILD_TYPE=Release \
    -DBUILD_SHARED_LIBS=OFF \
    -DWEBP_BUILD_ANIM_UTILS=OFF \
    -DWEBP_BUILD_CWEBP=OFF \
    -DWEBP_BUILD_DWEBP=OFF \
    -DWEBP_BUILD_EXTRAS=OFF \
    -DWEBP_BUILD_GIF2WEBP=OFF \
    -DWEBP_BUILD_IMG2WEBP=OFF \
    -DWEBP_BUILD_LIBWEBPMUX=OFF \
    -DWEBP_BUILD_VWEBP=OFF \
    -DWEBP_BUILD_WEBPINFO=OFF \
    -DWEBP_BUILD_WEBPMUX=OFF
cmake --build "${build_dir}" --parallel

webp_library="$(find "${build_dir}" -name 'libwebp.a' -type f -print -quit)"
readonly webp_library
sharpyuv_library="$(find "${build_dir}" -name 'libsharpyuv.a' -type f -print -quit)"
readonly sharpyuv_library
if [[ -z "${webp_library}" || -z "${sharpyuv_library}" ]]; then
    echo 'Compiled libwebp archives were not found.' >&2
    exit 1
fi

emcc "${script_dir}/register_webp_wasm.c" \
    "${webp_library}" \
    "${sharpyuv_library}" \
    -I"${source_dir}/src" \
    -O3 \
    -flto \
    -s MODULARIZE=1 \
    -s EXPORT_NAME=RegisterWebPModule \
    -s ENVIRONMENT=worker \
    -s FILESYSTEM=0 \
    -s ALLOW_MEMORY_GROWTH=1 \
    -s MAXIMUM_MEMORY=1073741824 \
    -s ASSERTIONS=0 \
    -s EXPORTED_FUNCTIONS='["_register_webp_encode","_register_webp_free","_register_webp_version","_malloc","_free"]' \
    -s EXPORTED_RUNTIME_METHODS='["HEAPU8","HEAPU32"]' \
    -o "${output_js}"

awk '
    { lines[NR] = $0 }
    END {
        last = NR
        while (last > 0 && lines[last] ~ /^[[:space:]]*$/) {
            --last
        }
        for (line = 1; line <= last; ++line) {
            print lines[line]
        }
    }
' "${source_dir}/COPYING" > "${project_dir}/_assets/register/image-optimizer/lib/register-webp.LICENSE"
chmod 0644 \
    "${output_js}" \
    "${output_js%.js}.wasm" \
    "${project_dir}/_assets/register/image-optimizer/lib/register-webp.LICENSE"
shasum -a 256 "${output_js}" "${output_js%.js}.wasm"
