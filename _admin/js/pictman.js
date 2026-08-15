/**
 * Picture manager JS functions
 *
 * Drag & drop, event handlers for the picture manager
 *
 * @copyright 2007-2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package S2
 */

var pictureManagerRoot = document.querySelector('[data-picture-manager]');
var pictureManagerConfig = pictureManagerRoot ? pictureManagerRoot.dataset : document.documentElement.dataset;
var sUrl = pictureManagerConfig.ajaxUrl || '';
var sPicturePrefix = pictureManagerConfig.picturePrefix || '';
var iMaxFileSize = Number.parseInt(pictureManagerConfig.maxFileSize || '0', 10);
var sFriendlyMaxFileSize = pictureManagerConfig.friendlyMaxFileSize || '';

var refreshFiles = function () {
};
var getCurDir = function () {
};

function strNatCmp(a, b) {
    function chunkify(t) {
        var tz = [], x = 0, y = -1, n = 0, i, j;

        while (i = (j = t.charAt(x++)).charCodeAt(0)) {
            var m = (i === 46 || (i >= 48 && i <= 57));
            if (m !== n) {
                tz[++y] = "";
                n = m;
            }
            tz[y] += j;
        }
        return tz;
    }

    var aa = chunkify(a.toLowerCase());
    var bb = chunkify(b.toLowerCase());

    for (x = 0; aa[x] && bb[x]; x++)
        if (aa[x] !== bb[x]) {
            var c = Number(aa[x]), d = Number(bb[x]);
            if (c === aa[x] && d === bb[x])
                return c - d;
            else
                return (aa[x] > bb[x]) ? 1 : -1;
        }

    return aa.length - bb.length;
}

var s2Retina = (function () {
    var is_local_storage = false;
    try {
        is_local_storage = 'localStorage' in window && window['localStorage'] !== null;
    } catch (e) {
        is_local_storage = false;
    }

    var is_retina = is_local_storage && !!(localStorage.getItem('s2_use_retina') - 0);

    return {
        'set': function (val) {
            is_retina = val;
            if (is_local_storage)
                localStorage.setItem('s2_use_retina', 0 + is_retina);
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
    button.value = s2_lang.insert;
    button.addEventListener('click', function () {
        fExecDouble();
    });
    container.append(document.createElement('br'), button);
}

function renderFileInformation(container, fileName, filePath, fileSize, dimensions, bits) {
    container.replaceChildren();

    container.append(document.createTextNode(s2_lang.file));
    var fileLink = document.createElement('a');
    fileLink.href = encodeURI(filePath);
    fileLink.target = '_blank';
    fileLink.rel = 'noopener';
    fileLink.textContent = filePath + ' ↑';
    container.append(fileLink);

    if (fileSize) {
        appendInformationLine(container, s2_lang.value + fileSize);
    }

    if (dimensions) {
        var size = dimensions.split('*');
        var width = Number.parseInt(size[0] || '0', 10);
        var height = Number.parseInt(size[1] || '0', 10);

        appendInformationLine(container, s2_lang.color + bits);
        appendInformationLine(container, s2_lang.size + width + '×' + height);

        var retinaSize = document.createElement('span');
        retinaSize.id = 's2_retina_size';
        retinaSize.hidden = !s2Retina.get();
        retinaSize.textContent = s2_lang.reduction + Math.round(width / 2) + '×' + Math.round(height / 2);
        container.append(retinaSize);

        var retinaLabel = document.createElement('label');
        var retinaCheckbox = document.createElement('input');
        retinaCheckbox.type = 'checkbox';
        retinaCheckbox.checked = s2Retina.get();
        retinaCheckbox.addEventListener('change', function () {
            s2Retina.set(retinaCheckbox.checked);
            retinaSize.hidden = !retinaCheckbox.checked;
        });
        retinaLabel.append(retinaCheckbox, document.createTextNode(s2_lang.retina_help));
        container.append(document.createElement('br'), retinaLabel);

        fExecDouble = function () {
            if (parentWnd.ReturnImage) {
                parentWnd.ReturnImage(
                    filePath,
                    s2Retina.get() ? Math.round(width / 2) : width,
                    s2Retina.get() ? Math.round(height / 2) : height
                );
            }
        };
        appendInsertButton(container);
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
        fileDeletionConfirmed = false;

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
        title: s2_lang.create_subfolder,
        'aria-label': s2_lang.create_subfolder
    }).text('+').appendTo(eButtons);
    $('<button>', {
        type: 'button',
        id: 'context_delete',
        class: 'is-dangerous',
        title: s2_lang.delete_folder,
        'aria-label': s2_lang.delete_folder
    }).text('−').appendTo(eButtons);
    $('body').append(eButtons);
    initContext();
    eButtons.detach();

    function folderRollback(data) {
        eButtons.remove();
        eButtons = null;
        $.jstree.rollback(data);
        eButtons = $('#context_buttons');
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
                    title: s2_lang.delete_title,
                    message: str_replace('%s', folderTree.jstree('get_text', selectedFolder), s2_lang.delete_item),
                    confirmLabel: s2_lang.delete_confirm,
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

            var newPath = d.rslt.obj.attr('data-path');
            pathCsrfToken = d.rslt.obj.attr('data-csrf-token') || '';

            if (path !== newPath) {
                path = newPath;
                fileTree.jstree('refresh', -1);
                replaceStrongText('fold_name', folderTree.jstree('get_text', d.rslt.obj));
            }
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
            fetch(endpointUrl, {method: 'POST', body: renameParams})
                .then(response => response.json())
                .then(d => {
                    if (!d || !d.success) {
                        folderRollback(data.rlbk);
                        if (d.message) {
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

                    var eSelected = folderTree.jstree('get_selected');
                    path = eSelected.attr('data-path');
                    replaceStrongText('fold_name', folderTree.jstree('get_text', eSelected));
                })
                .catch(() => {
                    folderRollback(data.rlbk);
                });
        })
        .bind('remove.jstree', function (e, data) {
            const endpointUrl = sUrl + 'action=delete_folder&path=' + encodeURIComponent(data.rslt.obj.attr('data-path'));

            const deleteParams = new URLSearchParams();
            deleteParams.append('csrf_token', data.rslt.obj.attr('data-csrf-token'));
            fetch(endpointUrl, {method: 'POST', body: deleteParams})
                .then(response => response.json())
                .then(d => {
                    if (!d || !d.success) {
                        folderRollback(data.rlbk);
                        if (d.message) {
                            PopupMessages.show(d.message);
                        }
                    }
                })
                .catch(() => {
                    folderRollback(data.rlbk);
                });
        })
        .bind('create.jstree', function (e, data) {
            const endpointUrl = sUrl + 'action=create_subfolder&name=' + encodeURIComponent(data.rslt.name)
                + '&path=' + encodeURIComponent(data.rslt.parent.attr('data-path'));
            const createParams = new URLSearchParams();
            createParams.append('csrf_token', data.rslt.parent.attr('data-csrf-token'));
            fetch(endpointUrl, {method: 'POST', body: createParams})
                .then(response => response.json())
                .then(d => {
                    if (!d.success) {
                        folderRollback(data.rlbk);
                        if (d.message) {
                            PopupMessages.show(d.message);
                        }
                    } else {
                        data.rslt.obj.attr('data-path', d.path);
                        if (d.csrf_token) {
                            data.rslt.obj.attr('data-csrf-token', d.csrf_token);
                        }
                        folderTree.jstree('rename_node', data.rslt.obj, d.name);
                    }
                })
                .catch(() => {
                    folderRollback(data.rlbk);
                });
        })
        .bind('move_node.jstree', function (e, data) {
            if (typeof (data.rslt.o.attr('data-path')) != 'undefined') {
                const endpointUrl = sUrl + 'action=move_folder&spath=' + encodeURIComponent(data.rslt.o.attr('data-path'))
                    + '&dpath=' + encodeURIComponent(data.rslt.np.attr('data-path'));
                const moveParams = new URLSearchParams();
                moveParams.append('csrf_token', data.rslt.o.attr('data-csrf-token'));
                moveParams.append('destination_csrf_token', data.rslt.np.attr('data-csrf-token'));
                fetch(endpointUrl, {method: 'POST', body: moveParams})
                    .then(response => response.json())
                    .then(d => {
                        if (!d || !d.success) {
                            folderRollback(data.rlbk);
                            if (d.message) {
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
                            path = folderTree.jstree('get_selected').attr('data-path');
                        }
                    })
                    .catch(() => {
                        folderRollback(data.rlbk);
                    });
            } else {
                var fileNames = [];
                data.rslt.o.each(function () {
                    fileNames.push('fname[]=' + encodeURIComponent($(this).attr('data-fname')));
                });

                const endpointUrl = sUrl + 'action=move_files&spath=' + encodeURIComponent(path)
                    + '&dpath=' + encodeURIComponent(data.rslt.np.attr('data-path'))
                    + '&' + fileNames.join('&');
                const fileMoveParams = new URLSearchParams();
                fileMoveParams.append('csrf_token', pathCsrfToken);
                fileMoveParams.append('destination_csrf_token', data.rslt.np.attr('data-csrf-token'));
                fetch(endpointUrl, {method: 'POST', body: fileMoveParams})
                    .then(response => response.json())
                    .then(d => {
                        folderRollback(data.rlbk);

                        if (!fileTree.children().length) {
                            fileTree.html('<ul></ul>'); // jstree fix (doesn't work after all roots disappearing)
                        }

                        if (!d || !d.success) {
                            fileTree.jstree('refresh', -1);
                            if (d.message) {
                                PopupMessages.show(d.message);
                            }
                        }
                    })
                    .catch(() => {
                        folderRollback(data.rlbk);
                        fileTree.jstree('refresh', -1);
                    });
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
                    loading: s2_lang.load,
                    new_node: 'new'
                }
            },
            sort: function (a, b) {
                return strNatCmp(this.get_text(a), this.get_text(b));
            },
            plugins: ['json_data', 'dnd', 'ui', 'crrm', 'hotkeys', 'sort']
        });

    refreshFiles = function () {
        fileTree.jstree('refresh', -1);
    };

    var fileTree = $('#files')
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
                title: s2_lang.delete_title,
                message: str_replace('%s', names.join(', '), s2_lang.delete_file),
                confirmLabel: s2_lang.delete_confirm,
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
        .bind('select_node.jstree', function (e, d) {
            fileTree.jstree('set_focus');

            var fileInformation = document.getElementById('finfo');
            if (!fileInformation) {
                return;
            }

            if (fileTree.jstree('get_selected').length === 1) {
                var fileName = d.rslt.obj.attr('data-fname');
                var filePath = sPicturePrefix + path + '/' + fileName;
                renderFileInformation(
                    fileInformation,
                    fileName,
                    filePath,
                    d.rslt.obj.attr('data-fsize'),
                    d.rslt.obj.attr('data-dim'),
                    d.rslt.obj.attr('data-bits')
                );
            } else {
                fExecDouble = function () {
                };
                fileInformation.replaceChildren();
            }
        })
        .bind('rename.jstree', function (e, data) {
            isRenaming = false;
            if (data.rslt.new_name === data.rslt.old_name) {
                return;
            }

            const endpointUrl = sUrl + 'action=rename_file&name=' + encodeURIComponent(data.rslt.new_name)
                + '&path=' + encodeURIComponent(path + '/' + data.rslt.obj.attr('data-fname'));
            const renameFileParams = new URLSearchParams();
            renameFileParams.append('csrf_token', pathCsrfToken);
            fetch(endpointUrl, {method: 'POST', body: renameFileParams})
                .then(response => response.json())
                .then(d => {
                    fileTree.jstree('deselect_all');
                    if (!d.success) {
                        fileTree.jstree('refresh', -1);
                        if (d.message) {
                            PopupMessages.show(d.message);
                        }
                    } else {
                        data.rslt.obj.attr('data-fname', d.new_name);
                    }
                })
                .catch(() => {
                    fileTree.jstree('refresh', -1);
                });
        })
        .bind('remove.jstree', function (e, data) {
            var fileNames = [];
            data.rslt.obj.each(function () {
                fileNames.push('fname[]=' + encodeURIComponent($(this).attr('data-fname')));
            });

            const endpointUrl = sUrl + 'action=delete_files&path=' + encodeURIComponent(path)
                + '&' + fileNames.join('&');

            const deleteFilesParams = new URLSearchParams();
            deleteFilesParams.append('csrf_token', pathCsrfToken);
            fetch(endpointUrl, {method: 'POST', body: deleteFilesParams})
                .then(response => response.json())
                .then(d => {
                    if (!d || !d.success) {
                        fileTree.jstree('refresh', -1);
                        if (d.message) {
                            PopupMessages.show(d.message);
                        }
                    }
                })
                .catch(() => {
                    fileTree.jstree('refresh', -1);
                });
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
                    $.jstree._reference(fileTree)._get_children(-1).each(function () {
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
                        return sUrl + 'action=load_files&path=' + encodeURIComponent(path);
                    },
                    success: function (data) {
                        if (data.length) {
                            $('#loadstatus').text('');
                            return data;
                        }
                        $('#loadstatus').text(data.message || s2_lang.unknown_error);
                        return false;
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
                    loading: s2_lang.load,
                    multiple_selection: s2_lang.multiple_files
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

        FileCounter(0, 0);
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
            PopupMessages.show(str_replace('%s', sFriendlyMaxFileSize, s2_lang.files_too_big) + not_sent);
        }

        e.preventDefault();
    }, false);
}

var FileCounter = (function (inc, new_value) {
    var i;

    return function (inc, new_value) {
        return (i = (typeof (new_value) == 'number' ? new_value : i + inc));
    }
}());

function SendDroppedFile(file) {
    var data = new FormData();
    data.append('pictures[]', file);
    data.append('dir', getCurDir());
    data.append('ajax', '1');
    data.append('csrf_token', getCurDirCsrfToken());

    handleFileUpload(data);
}

function handleFileUpload(data, callback) {
    const fileCounter = FileCounter(1);
    SetWait(true);
    fetch(sUrl + 'action=upload', {
        method: 'POST',
        body: data
    })
        .then(response => response.json())
        .then(responseJson => {
            if (!responseJson.success) {
                if (responseJson.errors) {
                    PopupMessages.show(responseJson.errors.join("\n"));
                } else if (responseJson.message) {
                    PopupMessages.show(responseJson.message);
                } else {
                    PopupMessages.show('Unknown error');
                }
            }
            if (callback) {
                callback(responseJson);
            }
        })
        .catch(error => {
            console.error('An error occurred during the upload:', error);
        })
        .finally(() => {
            const fileCounter = FileCounter(-1);
            if (0 === fileCounter) {
                SetWait(false);
                refreshFiles();
            }
        });
}

function UploadSubmit(eForm) {
    eForm.dir.value = getCurDir();
    eForm.csrf_token.value = getCurDirCsrfToken();
    const data = new FormData(eForm);

    FileCounter(0, 0);
    handleFileUpload(data, () => {
        eForm['pictures[]'].value = '';
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
    eDiv.classList.toggle('is-active', bWait);
    eDiv.setAttribute('aria-hidden', bWait ? 'false' : 'true');
    document.body.classList.toggle('is-busy', bWait);
}
