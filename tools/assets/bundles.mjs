// These ordered groups contain only product assets. Runtime selection requires
// an exact consecutive match, so disabled modules and editor permissions stay intact.
const publicScripts = [
    '_assets/register/comment-undo.js',
    '_assets/register/comment-editor.js',
    '_assets/register/offline.js',
    '_assets/register/live-updates.js',
    '_assets/register/partial-navigation.js',
    '_assets/register/public-auth.js',
];

export const assetBundles = [
    {path: '_assets/register/public.bundle.js', files: publicScripts},
    {path: '_assets/register/public-static.bundle.js', files: publicScripts.filter(path => !path.endsWith('/live-updates.js'))},
    {path: '_assets/register/public.bundle.css', files: [
        '_assets/register/content-security.css',
        '_assets/register/comment-undo.css',
        '_assets/register/comment-editor.css',
        '_assets/register/offline.css',
        '_assets/register/partial-navigation.css',
        '_assets/register/public-auth.css',
    ]},
    {path: '_assets/register/engagement.bundle.js', files: [
        '_assets/register/visitor/identity.js',
        '_assets/register/analytics/collector.js',
        '_assets/register/reactions/reactions.js',
    ]},
    // Keep the editor entry's directory: it resolves the image optimizer from currentScript.
    {path: '_assets/register/editor.bundle.js', files: [
        '_assets/register/editor/storage.js',
        '_assets/register/editor/request.js',
        '_assets/register/post-recovery.js',
        '_assets/register/editor/fields.js',
        '_assets/register/editor/boundaries.js',
        '_assets/register/editor/html-blocks.js',
        '_assets/register/editor/history.js',
        '_assets/register/editor/tags.js',
        '_assets/register/editor/recovery.js',
        '_assets/register/post-inplace.js',
    ]},
    {path: '_assets/register/blog-search.bundle.css', files: [
        '_assets/register/blog/site.css',
        '_assets/register/search/search.css',
    ]},
    {path: '_assets/register/blog-editor-search.bundle.css', files: [
        '_assets/register/blog/site.css',
        '_assets/register/post-recovery.css',
        '_assets/register/search/search.css',
    ]},
];
