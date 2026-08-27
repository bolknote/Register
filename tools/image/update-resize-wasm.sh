#!/usr/bin/env bash

set -euo pipefail

readonly package_version='2.1.1'
readonly package_sha256='0231e68692528372fd27a8b6610b7c8c84cf9cf3f256d97561a64996d9c2b962'
readonly package_url="https://registry.npmjs.org/@jsquash/resize/-/resize-${package_version}.tgz"
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly script_dir
project_dir="$(cd "${script_dir}/../.." && pwd)"
readonly project_dir
work_dir="$(mktemp -d)"
readonly work_dir
readonly archive_path="${work_dir}/resize.tgz"

cleanup() {
    rm -rf "${work_dir}"
}
trap cleanup EXIT

curl --fail --location --silent --show-error "${package_url}" --output "${archive_path}"
if [[ "$(shasum -a 256 "${archive_path}" | awk '{print $1}')" != "${package_sha256}" ]]; then
    echo '@jsquash/resize archive checksum mismatch.' >&2
    exit 1
fi
tar -xzf "${archive_path}" -C "${work_dir}"

cp "${work_dir}/package/lib/resize/pkg/squoosh_resize.js" "${project_dir}/_assets/register/image-optimizer/lib/register-resize.js"
cp "${work_dir}/package/lib/resize/pkg/squoosh_resize_bg.wasm" "${project_dir}/_assets/register/image-optimizer/lib/register-resize.wasm"
cp "${work_dir}/package/LICENSE" "${project_dir}/_assets/register/image-optimizer/lib/register-resize.LICENSE"
cp "${work_dir}/package/lib/resize/LICENSE.codec.md" "${project_dir}/_assets/register/image-optimizer/lib/register-resize-codec.LICENSE"

shasum -a 256 \
    "${project_dir}/_assets/register/image-optimizer/lib/register-resize.js" \
    "${project_dir}/_assets/register/image-optimizer/lib/register-resize.wasm"
