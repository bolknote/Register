# Editorial URL history

Changing a post's address, renaming a page, moving a page branch, or changing a
tag's URL now preserves the previous public address automatically. Addresses are
rare publication settings, so post, page and tag changes use their full
administration forms instead of cluttering the quick editor.

Aliases point to content/tag identities, not to the next alias. Repeated renames
therefore redirect straight to the current canonical URL with HTTP 301. Renaming
or moving a parent page records the previous addresses of its descendants too.
Tag history covers the tag page and its RSS and JSON Feed URLs. Query parameters
are retained. Redirects are limited to safe requests and do not expose unpublished
posts or pages hidden by an unpublished ancestor.

Historical addresses remain reserved for their original item. Another item
cannot take one, but the owner can return to one of its previous addresses. The
content write and history update share a transaction; a collision rolls back the
change instead of leaving the item with an untracked or conflicting address.

Tracking starts with this version. Addresses changed before it was installed
cannot be reconstructed automatically; existing imported aliases and manual
redirect rules remain available. The database migration from generation 30 to 31
adds tag aliases; content aliases use the existing table.

The existing content-alias table supports at most 255 bytes for a complete decoded
path (without the surrounding slashes), including all page ancestors. A rename
or move whose previous or new path exceeds this limit is rejected with a readable
validation error; the content and its history are rolled back together. Flat page
URLs are supported too; an ancestor rename does not change a descendant's flat URL.

Implementation: `Register\Url\UrlHistoryService`,
`Register\Admin\UrlHistoryDataProvider`, and the content/tag alias repositories.
Regression coverage lives in `_tests/unit/Register/Url/UrlHistoryTest.php` and
`_tests/integration/UrlHistoryCest.php`.
