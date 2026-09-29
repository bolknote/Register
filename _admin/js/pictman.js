/**
 * Picture manager JS functions
 *
 * Drag & drop, event handlers for the picture manager
 *
 * @copyright 2007-2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package Register
 */

var pictureManagerRoot = document.querySelector('[data-picture-manager]');
var pictureManagerConfig = pictureManagerRoot ? pictureManagerRoot.dataset : document.documentElement.dataset;
var sUrl = pictureManagerConfig.ajaxUrl || '';
var sPicturePrefix = pictureManagerConfig.picturePrefix || '';
var iMaxFileSize = Number.parseInt(pictureManagerConfig.maxFileSize || '0', 10);
var sFriendlyMaxFileSize = pictureManagerConfig.friendlyMaxFileSize || '';
var pendingFileUploads = 0;

var refreshFiles = function () {
};
var getCurDir = function () {
};

var mediaNameCollator = new Intl.Collator(undefined, {numeric: true, sensitivity: 'base'});

function strNatCmp(a, b) {
    return mediaNameCollator.compare(a, b);
}

var registerRetina = (function () {
    var is_local_storage = false;
    try {
        is_local_storage = 'localStorage' in window && window['localStorage'] !== null;
    } catch (e) {
        is_local_storage = false;
    }

    var is_retina = is_local_storage && !!(localStorage.getItem('register_use_retina') - 0);

    return {
        'set': function (val) {
            is_retina = val;
            if (is_local_storage)
                localStorage.setItem('register_use_retina', 0 + is_retina);
        },
        'get': function () {
            return is_retina;
        }
    };
}());

var parentWnd = opener || window.top || null,
    fExecDouble = function () {
    };

function isAudioFile(fileName) {
    var extension = fileName.includes('.') ? fileName.split('.').pop().toLowerCase() : '';

    return ['mp3', 'wav', 'ogg', 'flac'].includes(extension);
}

function mediaFileType(fileName) {
    var extension = fileName.includes('.') ? fileName.split('.').pop().toLowerCase() : '';
    if (['avif', 'bmp', 'gif', 'heic', 'heif', 'jpeg', 'jpg', 'png', 'svg', 'tif', 'tiff', 'webp'].includes(extension)) {
        return 'image';
    }
    if (['aac', 'aiff', 'alac', 'flac', 'm4a', 'mp3', 'oga', 'ogg', 'opus', 'wav'].includes(extension)) {
        return 'audio';
    }
    if (['avi', 'm4v', 'mkv', 'mov', 'mp4', 'mpeg', 'mpg', 'ogv', 'webm'].includes(extension)) {
        return 'video';
    }
    if (['csv', 'doc', 'docx', 'epub', 'md', 'odt', 'ods', 'odp', 'pdf', 'ppt', 'pptx', 'rtf', 'txt', 'xls', 'xlsx'].includes(extension)) {
        return 'document';
    }
    return 'other';
}

function filterMediaFiles(nodes, query, type, onHidden) {
    var search = query.trim().normalize('NFC').toLocaleLowerCase();
    var visible = 0;
    nodes.forEach(function (node) {
        var fileName = node.dataset.fname || '';
        var matches = fileName.normalize('NFC').toLocaleLowerCase().includes(search)
            && (type === 'all' || mediaFileType(fileName) === type);
        if (!matches && onHidden) {
            onHidden(node);
        }
        node.hidden = !matches;
        visible += Number(matches);
    });
    return {visible: visible, total: nodes.length};
}

function audioTitle(fileName) {
    return fileName.replace(/\.[^.]*$/, '').replace(/[_-]+/g, ' ').trim();
}

function replaceStrongText(elementId, text) {
    var container = document.getElementById(elementId);
    if (!container) {
        return;
    }

    var strong = document.createElement('strong');
    strong.textContent = text;
    container.replaceChildren(strong);
}

function appendInformationLine(container, text) {
    container.append(document.createElement('br'), document.createTextNode(text));
}

function appendInsertButton(container) {
    var button = document.createElement('input');
    button.type = 'button';
    button.className = 'link-as-button';
    button.value = register_lang.insert;
    button.addEventListener('click', function () {
        fExecDouble();
    });
    container.append(document.createElement('br'), button);
}

function renderFileInformation(container, fileName, filePath, fileSize, dimensions, bits) {
    container.replaceChildren();

    container.append(document.createTextNode(register_lang.file));
    var fileLink = document.createElement('a');
    // # and ? in filenames belong to the path, not a fragment or query.
    fileLink.href = encodeURI(filePath).replace(/#/g, '%23').replace(/\?/g, '%3F');
    fileLink.target = '_blank';
    fileLink.rel = 'noopener';
    fileLink.textContent = filePath + ' ↑';
    container.append(fileLink);

    if (fileSize) {
        appendInformationLine(container, register_lang.value + fileSize);
    }

    if (dimensions) {
        var size = dimensions.split('*');
        var width = Number.parseInt(size[0] || '0', 10);
        var height = Number.parseInt(size[1] || '0', 10);

        appendInformationLine(container, register_lang.color + bits);
        appendInformationLine(container, register_lang.size + width + '×' + height);

        var retinaSize = document.createElement('span');
        retinaSize.id = 'register_retina_size';
        retinaSize.hidden = !registerRetina.get();
        retinaSize.textContent = register_lang.reduction + Math.round(width / 2) + '×' + Math.round(height / 2);
        container.append(retinaSize);

        var retinaLabel = document.createElement('label');
        var retinaCheckbox = document.createElement('input');
        retinaCheckbox.type = 'checkbox';
        retinaCheckbox.checked = registerRetina.get();
        retinaCheckbox.addEventListener('change', function () {
            registerRetina.set(retinaCheckbox.checked);
            retinaSize.hidden = !retinaCheckbox.checked;
        });
        retinaLabel.append(retinaCheckbox, document.createTextNode(register_lang.retina_help));
        container.append(document.createElement('br'), retinaLabel);

        fExecDouble = function () {
            if (parentWnd.ReturnImage) {
                parentWnd.ReturnImage(
                    filePath,
                    registerRetina.get() ? Math.round(width / 2) : width,
                    registerRetina.get() ? Math.round(height / 2) : height
                );
            }
        };
        if (parentWnd.ReturnImage) {
            appendInsertButton(container);
        }
        return;
    }

    if (isAudioFile(fileName)) {
        fExecDouble = function () {
            if (parentWnd.ReturnAudio) {
                parentWnd.ReturnAudio(filePath, audioTitle(fileName));
            }
        };

        if (parentWnd.ReturnAudio) {
            appendInsertButton(container);
        }
        return;
    }

    fExecDouble = function () {
    };
}

$(function () {
    document.querySelector('input[name="pictures[]"]')?.addEventListener('change', function () {
        const selection = pictureManagerRoot?.querySelector('[data-media-upload-selection]');
        if (selection) {
            selection.textContent = Array.from(this.files || []).map(function (file) {
                return file.name;
            }).join(', ');
        }
        UploadChange(this);
    });

    $(document).keydown(function (e) {
        if (e.which === 27 && !document.querySelector('[data-admin-confirm-dialog][open]')) {
            parentWnd && parentWnd.ClosePictureDialog && parentWnd.ClosePictureDialog();
        }
    });

    var path = '',
        pathCsrfToken = '',
        isRenaming = false,
        folderDeletionConfirmed = false,
        fileDeletionConfirmed = false,
        fileLoadRequest = null,
        fileLoadError = '',
        filesLoading = true;

    var searchInput = pictureManagerRoot?.querySelector('[data-media-search]');
    var typeInput = pictureManagerRoot?.querySelector('[data-media-type]');

    function updateFileSelection() {
        var selected = fileTree.jstree('get_selected');
        var selectionBar = pictureManagerRoot?.querySelector('[data-media-selection-bar]');
        if (selectionBar) {
            selectionBar.hidden = filesLoading || selected.length === 0;
            selectionBar.querySelector('[data-media-selected-count]').textContent = (pictureManagerConfig.selectedCount || '')
                .replace('{{ count }}', selected.length);
            var renameButton = selectionBar.querySelector('[data-media-rename]');
            if (renameButton) {
                renameButton.disabled = selected.length !== 1;
            }
        }
        var fileInformation = document.getElementById('finfo');
        if (!fileInformation) return;
        if (filesLoading || selected.length !== 1) {
            fileInformation.replaceChildren();
            fExecDouble = function () {};
            return;
        }
        var file = selected.eq(0);
        var fileName = file.attr('data-fname');
        renderFileInformation(
            fileInformation,
            fileName,
            sPicturePrefix + path + '/' + fileName,
            file.attr('data-fsize'),
            file.attr('data-dim'),
            file.attr('data-bits')
        );
    }

    function updateFileView() {
        var nodes = Array.from(document.querySelectorAll('#files li[data-fname]'));
        nodes.forEach(function (node) {
            var link = node.querySelector('a');
            if (link) {
                link.dataset.fileSummary = [node.dataset.dim?.replace('*', ' × '), node.dataset.fsize].filter(Boolean).join(' · ');
            }
        });
        var counts = filterMediaFiles(nodes, searchInput?.value || '', typeInput?.value || 'all', function (node) {
            if (node.querySelector('a.jstree-clicked')) {
                fileTree.jstree('deselect_node', node);
            }
        });
        var count = pictureManagerRoot?.querySelector('[data-media-count]');
        if (count) {
            count.textContent = filesLoading ? register_lang.load : (pictureManagerConfig.fileCount || '')
                .replace('{{ visible }}', counts.visible).replace('{{ total }}', counts.total);
        }
        pictureManagerRoot?.querySelector('[data-media-empty]')?.toggleAttribute('hidden', filesLoading || !!fileLoadError || counts.total !== 0);
        pictureManagerRoot?.querySelector('[data-media-no-matches]')?.toggleAttribute('hidden', filesLoading || !!fileLoadError || counts.total === 0 || counts.visible !== 0);
        updateFileSelection();
    }

    function beginFileLoading() {
        filesLoading = true;
        fileLoadError = '';
        fExecDouble = function () {};
        // Files from the previous path must not remain actionable while the
        // new list is pending or if that request fails.
        if (fileTree) {
            fileTree.jstree('deselect_all');
            fileTree.children('ul').empty();
        }
        $('#loadstatus').text('');
        document.getElementById('finfo')?.replaceChildren();
        document.getElementById('files')?.setAttribute('aria-busy', 'true');
        pictureManagerRoot?.querySelector('[data-media-selection-bar]')?.setAttribute('hidden', '');
        pictureManagerRoot?.querySelector('[data-media-empty]')?.setAttribute('hidden', '');
        pictureManagerRoot?.querySelector('[data-media-no-matches]')?.setAttribute('hidden', '');
        var count = pictureManagerRoot?.querySelector('[data-media-count]');
        if (count) {
            count.textContent = register_lang.load;
        }
    }

    searchInput?.addEventListener('input', updateFileView);
    typeInput?.addEventListener('change', updateFileView);
    pictureManagerRoot?.querySelector('[data-media-reset]')?.addEventListener('click', function () {
        searchInput.value = '';
        typeInput.value = 'all';
        updateFileView();
        searchInput.focus();
    });
    pictureManagerRoot?.querySelector('[data-media-refresh]')?.addEventListener('click', function () {
        refreshFiles();
    });
    pictureManagerRoot?.querySelector('[data-media-rename]')?.addEventListener('click', function () {
        var selected = fileTree.jstree('get_selected');
        if (selected.length === 1) {
            isRenaming = true;
            fileTree.jstree('rename', selected);
        }
    });
    pictureManagerRoot?.querySelector('[data-media-delete]')?.addEventListener('click', function () {
        fileTree.jstree('remove', fileTree.jstree('get_selected'));
    });

    getCurDir = function () {
        return path;
    };

    getCurDirCsrfToken = function () {
        return pathCsrfToken;
    };

    function createFolder() {
        folderTree.jstree('create', null, 'first', {data: {title: 'new'}});
    }

    function initContext() {
        $('#context_buttons').click(function (e) {
            if (e.target.id === 'context_add') {
                createFolder();
            } else if (e.target.id === 'context_delete') {
                folderTree.jstree('remove', folderTree.jstree('get_selected'));
            }
        });
    }

    initFileDrop();

    var eButtons = $('<span>').attr('id', 'context_buttons');
    $('<button>', {
        type: 'button',
        id: 'context_add',
        title: register_lang.create_subfolder,
        'aria-label': register_lang.create_subfolder
    }).text('+').appendTo(eButtons);
    $('<button>', {
        type: 'button',
        id: 'context_delete',
        class: 'is-dangerous',
        title: register_lang.delete_folder,
        'aria-label': register_lang.delete_folder
    }).text('−').appendTo(eButtons);
    $('body').append(eButtons);
    initContext();
    eButtons.detach();

    function restoreFolderPosition(folder, parent) {
        if (!$.contains(folderTree[0], parent[0])) return;
        // Keep the live nodes and selection; replacing the old tree snapshot
        // would discard unrelated changes made while the request was pending.
        var previousParent = folder.parent().closest('li');
        if (!parent.children('ul').length) parent.append('<ul></ul>');
        folder.appendTo(parent.children('ul').first());
        parent.removeClass('jstree-leaf');
        if (!parent.is('.jstree-open, .jstree-closed')) parent.addClass('jstree-open');
        folderTree.jstree('sort', parent.children('ul'));
        if (previousParent.length && previousParent[0] !== parent[0]) {
            if (previousParent.children('ul').children('li').length) {
                folderTree.jstree('clean_node', previousParent);
            } else {
                folderTree.jstree('correct_state', previousParent);
            }
        }
        syncSelectedFolder();
    }

    function syncSelectedFolder() {
        var selected = folderTree.jstree('get_selected');
        var newPath = selected.attr('data-path');
        if (newPath === undefined) return;
        var changed = path !== newPath;
        path = newPath;
        pathCsrfToken = selected.attr('data-csrf-token') || '';
        replaceStrongText('fold_name', folderTree.jstree('get_text', selected));
        var location = pictureManagerRoot?.querySelector('[data-media-folder-path]');
        if (location) {
            location.textContent = path || folderTree.jstree('get_text', selected);
        }
        if (changed) refreshFiles();
    }

    var folderTree = $('#folders')
        .bind('before.jstree', function (e, data) {
            if (data.func !== 'remove') {
                return;
            }
            const selectedFolder = data.args[0];
            if (!selectedFolder.attr('data-path')) {
                e.stopImmediatePropagation();
                return false;
            }
            if (!folderDeletionConfirmed) {
                e.stopImmediatePropagation();
                window.AdminConfirm.ask({
                    title: register_lang.delete_title,
                    message: str_replace('%s', folderTree.jstree('get_text', selectedFolder), register_lang.delete_item),
                    confirmLabel: register_lang.delete_confirm,
                    dangerous: true
                }).then(function (confirmed) {
                    if (!confirmed) {
                        return;
                    }
                    folderDeletionConfirmed = true;
                    folderTree.jstree('remove', selectedFolder);
                    folderDeletionConfirmed = false;
                });
                return false;
            }
        })
        .bind('dblclick.jstree', function (e) {
            if (!isRenaming && e.target.nodeName === 'A') {
                isRenaming = true;
                folderTree.jstree('rename', e.target);
            }
        })
        .bind('select_node.jstree', function (e, d) {
            folderTree.jstree('set_focus');

            if (eButtons) {
                eButtons.detach();
                folderTree.find('.jstree-clicked').append(eButtons);
            }

            syncSelectedFolder();
        })
        .bind('deselect_node.jstree', function (e, d) {
            eButtons.detach();
        })
        .bind('rename.jstree', function (e, data) {
            isRenaming = false;
            if (data.rslt.new_name === data.rslt.old_name) {
                return;
            }

            const endpointUrl = sUrl + 'action=rename_folder&name=' + encodeURIComponent(data.rslt.new_name)
                + '&path=' + encodeURIComponent(data.rslt.obj.attr('data-path'));
            const renameParams = new URLSearchParams();
            renameParams.append('csrf_token', data.rslt.obj.attr('data-csrf-token'));
            const restoreFolderName = function () {
                if (!$.contains(folderTree[0], data.rslt.obj[0])) return;
                folderTree.jstree('rename_node', data.rslt.obj, data.rslt.old_name);
                syncSelectedFolder();
            };
            fetch(endpointUrl, {method: 'POST', body: renameParams})
                .then(response => response.json())
                .then(d => {
                    if (!d?.success) {
                        restoreFolderName();
                        if (d?.message) {
                            PopupMessages.show(d.message);
                        }
                        return;
                    }

                    var len = data.rslt.obj.attr('data-path').length;
                    data.rslt.obj.attr('data-path', d.new_path).find('li').each(function () {
                        $(this).attr('data-path', d.new_path + $(this).attr('data-path').substring(len));
                    });
                    if (d.csrf_token) {
                        data.rslt.obj.attr('data-csrf-token', d.csrf_token);
                    }

                    syncSelectedFolder();
                })
                .catch(restoreFolderName);
        })
        .bind('remove.jstree', function (e, data) {
            const endpointUrl = sUrl + 'action=delete_folder&path=' + encodeURIComponent(data.rslt.obj.attr('data-path'));

            const deleteParams = new URLSearchParams();
            deleteParams.append('csrf_token', data.rslt.obj.attr('data-csrf-token'));
            const restoreDeletedFolder = function () {
                restoreFolderPosition(data.rslt.obj, data.rslt.parent);
            };
            fetch(endpointUrl, {method: 'POST', body: deleteParams})
                .then(response => response.json())
                .then(d => {
                    if (!d?.success) {
                        restoreDeletedFolder();
                        if (d?.message) {
                            PopupMessages.show(d.message);
                        }
                    }
                })
                .catch(restoreDeletedFolder);
        })
        .bind('create.jstree', function (e, data) {
            const endpointUrl = sUrl + 'action=create_subfolder&name=' + encodeURIComponent(data.rslt.name)
                + '&path=' + encodeURIComponent(data.rslt.parent.attr('data-path'));
            const createParams = new URLSearchParams();
            createParams.append('csrf_token', data.rslt.parent.attr('data-csrf-token'));
            const discardCreatedFolder = function () {
                if (!$.contains(folderTree[0], data.rslt.obj[0])) return;
                if (data.rslt.obj.is(folderTree.jstree('get_selected'))) {
                    folderTree.jstree('deselect_all');
                    folderTree.jstree('select_node', data.rslt.parent);
                }
                // Only the provisional folder belongs to this operation.
                // Restoring its old tree snapshot would undo later selections
                // and unrelated folder changes.
                folderTree.jstree('delete_node', data.rslt.obj);
            };
            fetch(endpointUrl, {method: 'POST', body: createParams})
                .then(response => response.json())
                .then(d => {
                    if (!d?.success) {
                        discardCreatedFolder();
                        if (d?.message) {
                            PopupMessages.show(d.message);
                        }
                    } else {
                        data.rslt.obj.attr('data-path', d.path);
                        if (d.csrf_token) {
                            data.rslt.obj.attr('data-csrf-token', d.csrf_token);
                        }
                        folderTree.jstree('rename_node', data.rslt.obj, d.name);
                        syncSelectedFolder();
                    }
                })
                .catch(discardCreatedFolder);
        })
        .bind('move_node.jstree', function (e, data) {
            if (typeof (data.rslt.o.attr('data-path')) != 'undefined') {
                const endpointUrl = sUrl + 'action=move_folder&spath=' + encodeURIComponent(data.rslt.o.attr('data-path'))
                    + '&dpath=' + encodeURIComponent(data.rslt.np.attr('data-path'));
                const moveParams = new URLSearchParams();
                moveParams.append('csrf_token', data.rslt.o.attr('data-csrf-token'));
                moveParams.append('destination_csrf_token', data.rslt.np.attr('data-csrf-token'));
                const restoreMovedFolder = function () {
                    restoreFolderPosition(data.rslt.o, data.rslt.op);
                };
                fetch(endpointUrl, {method: 'POST', body: moveParams})
                    .then(response => response.json())
                    .then(d => {
                        if (!d?.success) {
                            restoreMovedFolder();
                            if (d?.message) {
                                PopupMessages.show(d.message);
                            }
                        } else {
                            var len = data.rslt.o.attr('data-path').length;
                            data.rslt.o.attr('data-path', d.new_path).find('li').each(function () {
                                $(this).attr('data-path', d.new_path + $(this).attr('data-path').substring(len));
                            });
                            if (d.csrf_token) {
                                data.rslt.o.attr('data-csrf-token', d.csrf_token);
                            }
                            syncSelectedFolder();
                        }
                    })
                    .catch(restoreMovedFolder);
            } else {
                var fileNames = [];
                data.rslt.o.each(function () {
                    fileNames.push('fname[]=' + encodeURIComponent($(this).attr('data-fname')));
                });

                const sourcePath = path;
                const destinationPath = data.rslt.np.attr('data-path');
                const endpointUrl = sUrl + 'action=move_files&spath=' + encodeURIComponent(sourcePath)
                    + '&dpath=' + encodeURIComponent(destinationPath)
                    + '&' + fileNames.join('&');
                const fileMoveParams = new URLSearchParams();
                fileMoveParams.append('csrf_token', pathCsrfToken);
                fileMoveParams.append('destination_csrf_token', data.rslt.np.attr('data-csrf-token'));
                // A cross-tree drop temporarily inserts file nodes into the
                // folder tree. Remove just those nodes, preserving later folder
                // selections and changes instead of restoring an old snapshot.
                data.rslt.o.remove();
                if (data.rslt.np.children('ul').children('li').length) {
                    folderTree.jstree('clean_node', data.rslt.np);
                } else {
                    folderTree.jstree('correct_state', data.rslt.np);
                }
                if (!fileTree.children('ul').length) {
                    fileTree.append('<ul></ul>');
                }
                fileTree.jstree('deselect_all');
                updateFileView();
                const refreshMovedFiles = function () {
                    if (path === sourcePath || path === destinationPath) refreshFiles();
                };
                fetch(endpointUrl, {method: 'POST', body: fileMoveParams})
                    .then(response => response.json())
                    .then(d => {
                        if (!d?.success && d?.message) {
                            PopupMessages.show(d.message);
                        }
                        refreshMovedFiles();
                    })
                    .catch(refreshMovedFiles);
            }
        })
        .bind('focus', function () {
            folderTree.jstree('set_focus');
        })
        .jstree({
            ui: {
                select_limit: 1,
                initially_select: ['node_1']
            },
            hotkeys: {
                'n': function () {
                    createFolder();
                    return false;
                },
                'f2': function () {
                    this.rename(this.data.ui.last_selected || this.data.ui.hovered);
                    return false;
                }
            },
            json_data: {
                ajax: {
                    url: function (node) {
                        return sUrl + 'action=load_folders' + (node.attr ? '&path=' + encodeURIComponent(node.attr('data-path')) : '');
                    }
                }
            },
            crrm: {
                input_width_limit: 1000,
                move: {
                    check_move: function (m) {
                        return (typeof (m.np.attr('data-path')) !== 'undefined' && m.np.attr('data-path') !== path);
                    }
                }
            },
            core: {
                animation: 150,
                initially_open: ['node_1'],
                progressive_render: true,
                open_parents: false,
                strings: {
                    loading: register_lang.load,
                    new_node: 'new'
                }
            },
            sort: function (a, b) {
                return strNatCmp(this.get_text(a), this.get_text(b));
            },
            plugins: ['json_data', 'dnd', 'ui', 'crrm', 'hotkeys', 'sort']
        });

    refreshFiles = function () {
        beginFileLoading();
        fileTree.jstree('refresh', -1);
    };

    var fileTree = $('#files')
        .bind('loaded.jstree load_node.jstree refresh.jstree', function () {
            filesLoading = false;
            document.getElementById('files')?.removeAttribute('aria-busy');
            updateFileView();
        })
        .bind('deselect_node.jstree deselect_all.jstree', updateFileSelection)
        .bind('before.jstree', function (e, data) {
            if (data.func !== 'remove' || fileDeletionConfirmed) {
                return;
            }
            var names = [];
            const selectedFiles = fileTree.jstree('get_selected');
            selectedFiles.each(function () {
                names.push(fileTree.jstree('get_text', this));
            });
            if (names.length === 0) {
                return;
            }
            e.stopImmediatePropagation();
            window.AdminConfirm.ask({
                title: register_lang.delete_title,
                message: str_replace('%s', names.join(', '), register_lang.delete_file),
                confirmLabel: register_lang.delete_confirm,
                dangerous: true
            }).then(function (confirmed) {
                if (!confirmed) {
                    return;
                }
                fileDeletionConfirmed = true;
                fileTree.jstree('remove', selectedFiles);
                fileDeletionConfirmed = false;
            });
            return false;
        })
        .bind('dblclick.jstree', function (e) {
            if (!isRenaming && (e.target.nodeName === 'A' || e.target.nodeName === 'INS')) {
                isRenaming = true;
                fileTree.jstree('rename', e.target);
            }
        })
        .bind('select_node.jstree', function () {
            fileTree.jstree('set_focus');
            updateFileSelection();
        })
        .bind('rename.jstree', function (e, data) {
            isRenaming = false;
            if (data.rslt.new_name === data.rslt.old_name) {
                return;
            }

            const renamedPath = path;
            const endpointUrl = sUrl + 'action=rename_file&name=' + encodeURIComponent(data.rslt.new_name)
                + '&path=' + encodeURIComponent(renamedPath + '/' + data.rslt.obj.attr('data-fname'));
            const renameFileParams = new URLSearchParams();
            renameFileParams.append('csrf_token', pathCsrfToken);
            const refreshRenamedFiles = function () {
                if (path === renamedPath) refreshFiles();
            };
            fetch(endpointUrl, {method: 'POST', body: renameFileParams})
                .then(response => response.json())
                .then(d => {
                    if (!d?.success && d?.message) {
                        PopupMessages.show(d.message);
                    }
                    // The original node may have been replaced by a refresh
                    // while this request was pending.
                    refreshRenamedFiles();
                })
                .catch(refreshRenamedFiles);
        })
        .bind('remove.jstree', function (e, data) {
            var fileNames = [];
            data.rslt.obj.each(function () {
                fileNames.push('fname[]=' + encodeURIComponent($(this).attr('data-fname')));
            });

            const deletedPath = path;
            const endpointUrl = sUrl + 'action=delete_files&path=' + encodeURIComponent(deletedPath)
                + '&' + fileNames.join('&');

            const deleteFilesParams = new URLSearchParams();
            deleteFilesParams.append('csrf_token', pathCsrfToken);
            updateFileView();
            const refreshDeletedFiles = function () {
                if (path === deletedPath) refreshFiles();
            };
            fetch(endpointUrl, {method: 'POST', body: deleteFilesParams})
                .then(response => response.json())
                .then(d => {
                    if (!d?.success && d?.message) {
                        PopupMessages.show(d.message);
                    }
                    refreshDeletedFiles();
                })
                .catch(refreshDeletedFiles);
        })
        .bind('focus', function () {
            fileTree.jstree('set_focus');
        })
        .jstree({
            ui: {
                select_limit: -1
            },
            hotkeys: {
                'del': function () {
                    fileTree.jstree('remove');
                },
                'ctrl+a': function () {
                    $.jstree._reference(fileTree)._get_children(-1).filter(function () {
                        return !this.hidden;
                    }).each(function () {
                        fileTree.jstree('select_node', this);
                    });
                    return false;
                },
                'f2': function () {
                    this.rename(this.data.ui.last_selected || this.data.ui.hovered);
                    return false;
                }
            },
            json_data: {
                ajax: {
                    url: function () {
                        beginFileLoading();
                        return sUrl + 'action=load_files&path=' + encodeURIComponent(path);
                    },
                    beforeSend: function (xhr, settings) {
                        var previous = fileLoadRequest;
                        fileLoadRequest = xhr;
                        // Guard the complete callback chain, including jsTree's
                        // DOM updates and the global error handler. Its success
                        // hook alone cannot stop jsTree from rendering stale data.
                        ['success', 'error'].forEach(function (name) {
                            var callbacks = Array.isArray(settings[name]) ? settings[name] : [settings[name]];
                            settings[name] = function (...args) {
                                if (fileLoadRequest !== xhr) return;
                                callbacks.forEach(callback => {
                                    if (typeof callback === 'function') callback.apply(this, args);
                                });
                            };
                        });
                        if (previous) previous.abort();
                    },
                    complete: function (xhr) {
                        if (fileLoadRequest === xhr) fileLoadRequest = null;
                    },
                    success: function (data) {
                        filesLoading = false;
                        fileLoadError = '';
                        document.getElementById('files')?.removeAttribute('aria-busy');
                        if (Array.isArray(data) && data.length) {
                            $('#loadstatus').text('');
                            return data;
                        }
                        if (!Array.isArray(data) && !(typeof data?.message === 'string' && data.message === pictureManagerConfig.emptyDirectory)) {
                            fileLoadError = typeof data?.message === 'string' && data.message.trim() !== ''
                                ? data.message : register_lang.unknown_error;
                        }
                        $('#loadstatus').text(fileLoadError);
                        window.setTimeout(updateFileView, 0);
                        return [];
                    },
                    error: function (xhr, status) {
                        filesLoading = false;
                        if (status !== 'success') {
                            fileLoadError = register_lang.unknown_error;
                        }
                        document.getElementById('files')?.removeAttribute('aria-busy');
                        $('#loadstatus').text(fileLoadError);
                        updateFileView();
                    }
                }
            },
            crrm: {
                move: {
                    check_move: function (m) {
                        return false;
                    }
                }
            },
            core: {
                strings: {
                    loading: register_lang.load,
                    multiple_selection: register_lang.multiple_files
                }
            },
            sort: function (a, b) {
                return strNatCmp(this.get_text(a), this.get_text(b));
            },
            plugins: ['json_data', 'dnd', 'ui', 'crrm', 'hotkeys', 'sort']
        });
})
    .ajaxStart(function () {
        SetWait(true);
    })
    .ajaxStop(function () {
        SetWait(false);
    });


$.ajaxPrefilter(function (options, originalOptions, jqXHR) {
    var successCheck = function (data, textStatus, jqXHR) {
            checkAjaxStatus(jqXHR);
        },
        errorCheck = function (jqXHR, textStatus, errorThrown) {
            checkAjaxStatus(jqXHR);
        };

    options.success = options.success instanceof Array ? options.success.unshift(successCheck) : (typeof (options.success) == 'function' ? [successCheck, options.success] : successCheck);
    options.error = options.error instanceof Array ? options.error.unshift(errorCheck) : (typeof (options.error) == 'function' ? [errorCheck, options.error] : errorCheck);
});

function initFileDrop() {
    if (!document.addEventListener)
        return;

    var brd = document.getElementById('brd');
    brd.addEventListener('dragover', function (e) {
        e.preventDefault();
    }, false);

    brd.addEventListener('dragenter', function (e) {
        var dt = e.dataTransfer;
        if (!dt)
            return;

        if (dt.types.contains && !dt.types.contains("Files")) { //FF
            return;
        }
        if (dt.types.indexOf && dt.types.indexOf("Files") === -1) { //Chrome
            return;
        }

        document.getElementById('brd').className = 'accept_drag';

        e.preventDefault();
    }, false);

    brd.addEventListener('dragleave', function (e) {
        document.getElementById('brd').className = '';
        e.preventDefault();
    }, false);
    brd.addEventListener('drop', function (e) {
        var dt = e.dataTransfer;
        if (!dt || !dt.files) {
            return;
        }

        document.getElementById('brd').className = '';

        var files = dt.files,
            not_sent = '';

        for (var i = files.length; i--;) {
            if (files[i].size <= iMaxFileSize) {
                SendDroppedFile(files[i]);
            } else {
                not_sent += '<br />' + files[i].name;
            }
        }

        if (not_sent !== '') {
            PopupMessages.show(str_replace('%s', sFriendlyMaxFileSize, register_lang.files_too_big) + not_sent);
        }

        e.preventDefault();
    }, false);
}

function SendDroppedFile(file) {
    var data = new FormData();
    data.append('pictures[]', file);
    data.append('dir', getCurDir());
    data.append('ajax', '1');
    data.append('csrf_token', getCurDirCsrfToken());

    handleFileUpload(data);
}

async function handleFileUpload(data, callback) {
    pendingFileUploads++;
    SetWait(true);
    const failed = register_lang.upload_failed || 'Unable to upload files. Please try again.';
    try {
        const response = await fetch(sUrl + 'action=upload', {
            method: 'POST',
            body: data,
            registerHandleErrorsInline: true
        });
        const payload = await response.json().catch(() => null);
        if (!response.ok || payload?.success !== true) {
            const errors = Array.isArray(payload?.errors)
                ? payload.errors.filter(error => typeof error === 'string' && error.trim() !== '') : [];
            const message = errors.join('\n') || (typeof payload?.message === 'string' && payload.message.trim() !== ''
                ? payload.message : failed);
            PopupMessages.show(message, null, null, response.status === 401 ? 'login' : null);
        }
    } catch (error) {
        PopupMessages.show(failed);
        console.error('An error occurred during the upload:', error);
    } finally {
        pendingFileUploads--;
        SetWait(pendingFileUploads > 0);
        if (callback) callback();
        if (pendingFileUploads === 0) refreshFiles();
    }
}

function UploadSubmit(eForm) {
    eForm.dir.value = getCurDir();
    eForm.csrf_token.value = getCurDirCsrfToken();
    const data = new FormData(eForm);
    const input = eForm.elements['pictures[]'];
    const submittedFiles = Array.from(input.files);

    handleFileUpload(data, () => {
        // A delayed reply must not clear a later selection, even if its files
        // have the same names. Release this selection after failures too so
        // choosing the same files again triggers another change event.
        if (input.files.length !== submittedFiles.length
            || !submittedFiles.every((file, index) => file === input.files[index])) return;
        input.value = '';
        const selection = pictureManagerRoot?.querySelector('[data-media-upload-selection]');
        if (selection) {
            selection.textContent = '';
        }
    });
}

function UploadChange(eItem) {
    let eForm = eItem.form;
    setTimeout(function () {
        UploadSubmit(eForm);
    }, 0);
}

function SetWait(bWait) {
    const eDiv = document.getElementById('loading_pict');
    if (!eDiv) {
        return;
    }
    // Folder requests use jQuery's global ajaxStop while uploads use fetch.
    bWait = bWait || pendingFileUploads > 0;
    eDiv.classList.toggle('is-active', bWait);
    eDiv.setAttribute('aria-hidden', bWait ? 'false' : 'true');
    document.body.classList.toggle('is-busy', bWait);
}
