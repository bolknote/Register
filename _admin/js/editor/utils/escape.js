/**
 * HTML escaping helpers for editor modules in Register.
 *
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;')
        .replace(/`/g, '&#96;');
}

function sanitizeUrlForAttribute(url) {
    // The media manager supplies a raw file path, including literal # and ?.
    return escapeHtml(encodeURI(String(url)).replace(/#/g, '%23').replace(/\?/g, '%3F'));
}

export {escapeHtml, sanitizeUrlForAttribute};
