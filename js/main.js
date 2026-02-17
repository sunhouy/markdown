/**
 * Vditor 初始化、界面与功能绑定
 */
document.addEventListener('DOMContentLoaded', function() {
    'use strict';

    var isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
    var loading = document.getElementById('loading');
    if (loading) loading.style.display = 'block';

    // 全局状态
    window.nightMode = localStorage.getItem('vditor_night_mode') === 'true';
    window.currentUser = JSON.parse(localStorage.getItem('vditor_user') || 'null');
    window.currentFileId = null;
    window.files = JSON.parse(localStorage.getItem('vditor_files') || '[]');
    window.autoSaveTimer = null;
    window.syncInterval = null;
    window.lastSyncedContent = {};
    window.unsavedChanges = {};
    window.vditor = null;

    if (window.nightMode) {
        document.body.classList.add('night-mode');
        var modeToggleEl = document.getElementById('modeToggle');
        if (modeToggleEl) modeToggleEl.innerHTML = '<i class="fas fa-sun"></i>';
    }

    var modeMap = {
        wysiwyg: { name: '所见即所得', icon: 'fas fa-eye' },
        ir: { name: '即时渲染', icon: 'fas fa-bolt' },
        sv: { name: '分屏预览', icon: 'fas fa-columns' }
    };

    var editorConfig = {
        height: '100%',
        width: '100%',
        placeholder: '开始编辑...支持 Markdown 语法',
        toolbar: ['emoji', 'br', 'bold', 'italic', 'strike', '|', 'line', 'quote', 'list', 'ordered-list', 'check', 'outdent', 'indent', 'code', 'inline-code', 'insert-after', 'insert-before', 'upload', 'link', 'table', 'record', 'edit-mode', 'both', 'preview', 'fullscreen', 'outline', 'code-theme', 'content-theme', 'export', 'info', 'help', 'br'],
        customWysiwygToolbar: undefined,
        theme: window.nightMode ? 'dark' : 'classic',
        mode: localStorage.getItem('vditor_editor_mode') || 'ir',
        cache: { enable: true, id: 'vditor-mobile-optimized' },
        outline: { enable: false },
        hint: { emoji: {} },
        upload: {
            accept: 'image/*,.jpg,.jpeg,.png,.gif,.bmp,.webp,.svg,.mp4,.mp3,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.rar,.7z',
            handler: function(files) { return window.uploadFiles(files, true); }
        },
        after: function() {
            if (loading) loading.style.display = 'none';
            initUserInterface();
            initMobileFeatures();
            document.addEventListener('keydown', function(e) {
                if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); window.saveCurrentFile(true); }
                if (e.ctrlKey && e.shiftKey && e.key === 'L') { e.preventDefault(); window.toggleNightMode(); }
            });
            document.addEventListener('click', function(e) {
                var dropdown = document.getElementById('mobileDropdown');
                var menuBtn = document.getElementById('mobileMenuBtn');
                var overlay = document.getElementById('mobileActionSheetOverlay');
                var userMenu = document.getElementById('userMenuDropdown');
                var fileListSidebar = document.getElementById('fileListSidebar');
                var mobileFileBtn = document.getElementById('mobileFileBtn');
                
                if (menuBtn && dropdown && !menuBtn.contains(e.target) && !dropdown.contains(e.target)) dropdown.classList.remove('show');
                if (overlay && e.target === overlay) window.hideMobileActionSheet();
                if (userMenu && !document.getElementById('mobileLoginBtn').contains(e.target) && !userMenu.contains(e.target)) userMenu.classList.remove('show');
                if (fileListSidebar && mobileFileBtn && !mobileFileBtn.contains(e.target) && !fileListSidebar.contains(e.target)) fileListSidebar.classList.remove('show');
            });
            if (window.vditor && window.vditor.vditor && window.vditor.vditor.ir) {
                window.vditor.vditor.ir.element.addEventListener('input', function() {
                    if (window.currentFileId) {
                        window.unsavedChanges[window.currentFileId] = true;
                        window.startAutoSave();
                    }
                });
            }
        }
    };

    window.vditor = new Vditor('vditor', editorConfig);

    function initUserInterface() {
        if (window.currentUser) {
            window.showUserInfo();
            window.startAutoSync();
            window.loadFilesFromServer();
        } else {
            // 检查是否是分享链接
            const urlParams = new URLSearchParams(window.location.search);
            const shareId = urlParams.get('share_id');
            
            if (!shareId) {
                // 不是分享链接，显示未登录提示
                window.showMessage('未登录');
            }
            window.loadLocalFiles();
        }
        var fileListClose = document.getElementById('fileListClose');
        if (fileListClose) fileListClose.addEventListener('click', function() { document.getElementById('fileListSidebar').classList.remove('show'); });
        var addFileBtn = document.getElementById('addFileBtn');
        if (addFileBtn) addFileBtn.addEventListener('click', window.createNewFile);
        var mobileFileBtn = document.getElementById('mobileFileBtn');
        if (mobileFileBtn) mobileFileBtn.addEventListener('click', function() { document.getElementById('fileListSidebar').classList.toggle('show'); });
    }

    function initMobileFeatures() {
        var dropdown = document.getElementById('mobileDropdown');
        function closeDrop() { if (dropdown) dropdown.classList.remove('show'); }

        var mobileShareBtn = document.getElementById('mobileShareBtn');
        if (mobileShareBtn) mobileShareBtn.addEventListener('click', function() { window.showShareDialog(); closeDrop(); });
        var mobilePrintBtn = document.getElementById('mobilePrintBtn');
        if (mobilePrintBtn) mobilePrintBtn.addEventListener('click', function() { window.showPrintDialog(); closeDrop(); });
        var mobileFormulaBtn = document.getElementById('mobileFormulaBtn');
        if (mobileFormulaBtn) mobileFormulaBtn.addEventListener('click', function() { if (typeof showFormulaPicker === 'function') showFormulaPicker(); });
        var mobileChartBtn = document.getElementById('mobileChartBtn');
        if (mobileChartBtn) mobileChartBtn.addEventListener('click', function() { window.showChartPicker(); });
        var mobileMenuBtn = document.getElementById('mobileMenuBtn');
        if (mobileMenuBtn) mobileMenuBtn.addEventListener('click', function(e) { e.stopPropagation(); if (dropdown) dropdown.classList.toggle('show'); });
        var mobileUndoBtn = document.getElementById('mobileUndoBtn');
        if (mobileUndoBtn) mobileUndoBtn.addEventListener('click', function() { 
            console.log('=== Mobile Undo button clicked ===');
            console.log('window.vditor exists:', window.vditor !== undefined);
            if (window.vditor) {
                console.log('window.vditor keys:', Object.keys(window.vditor));
                
                // 尝试不同的方式获取模式
                let currentMode;
                if (window.vditor.vditor && window.vditor.vditor.currentMode) {
                    currentMode = window.vditor.vditor.currentMode;
                } else if (window.vditor.vditor && window.vditor.vditor.mode) {
                    currentMode = window.vditor.vditor.mode;
                } else if (window.vditor.getMode) {
                    currentMode = window.vditor.getMode();
                } else if (window.vditor.getCurrentMode) {
                    currentMode = window.vditor.getCurrentMode();
                }
                
                console.log('Current mode:', currentMode);
                
                console.log('Checking vditor.vditor.undo:', window.vditor.vditor ? typeof window.vditor.vditor.undo : 'vditor.vditor not exist');
                
                // 检查 undo 对象的结构
                if (window.vditor.vditor && window.vditor.vditor.undo) {
                    console.log('vditor.vditor.undo is an object:', typeof window.vditor.vditor.undo === 'object');
                    if (typeof window.vditor.vditor.undo === 'object') {
                        console.log('vditor.vditor.undo keys:', Object.keys(window.vditor.vditor.undo));
                    }
                }
                
                console.log('Checking vditor.vditor.ir:', window.vditor.vditor ? (window.vditor.vditor.ir ? 'exists' : 'not exist') : 'vditor.vditor not exist');
                console.log('Checking vditor.vditor.ir.undo:', window.vditor.vditor && window.vditor.vditor.ir ? typeof window.vditor.vditor.ir.undo : 'not exist');
                
                // 直接尝试模拟键盘事件（优先使用，因为键盘快捷键已经正常工作）
                console.log('Trying to simulate keyboard event for undo');
                try {
                    const event = new KeyboardEvent('keydown', {
                        key: 'z',
                        ctrlKey: true,
                        bubbles: true,
                        cancelable: true
                    });
                    
                    // 找到编辑器元素并发送事件
                    if (window.vditor.vditor && window.vditor.vditor.element) {
                        console.log('Dispatching undo event to editor element');
                        window.vditor.vditor.element.dispatchEvent(event);
                        console.log('Undo event dispatched successfully');
                    } else if (window.vditor.vditor && window.vditor.vditor.ir && window.vditor.vditor.ir.element) {
                        console.log('Dispatching undo event to ir element');
                        window.vditor.vditor.ir.element.dispatchEvent(event);
                        console.log('Undo event dispatched successfully');
                    } else {
                        console.log('No suitable element found for event dispatch');
                    }
                } catch (error) {
                    console.error('Error simulating undo event:', error);
                }
                
                // 尝试其他方法作为备份
                console.log('Trying alternative methods');
                
                // 尝试调用顶层方法
                if (typeof window.vditor.undo === 'function') {
                    console.log('Calling window.vditor.undo()');
                    try {
                        window.vditor.undo();
                        console.log('undo() called successfully');
                    } catch (error) {
                        console.error('Error calling window.vditor.undo():', error);
                    }
                }
                
                // 记录最终状态
                console.log('Undo operation completed');
                
                
            }
            closeDrop(); 
        });
        var mobileRedoBtn = document.getElementById('mobileRedoBtn');
        if (mobileRedoBtn) mobileRedoBtn.addEventListener('click', function() { 
            console.log('=== Mobile Redo button clicked ===');
            console.log('window.vditor exists:', window.vditor !== undefined);
            if (window.vditor) {
                console.log('window.vditor keys:', Object.keys(window.vditor));
                
                // 尝试不同的方式获取模式
                let currentMode;
                if (window.vditor.vditor && window.vditor.vditor.currentMode) {
                    currentMode = window.vditor.vditor.currentMode;
                } else if (window.vditor.vditor && window.vditor.vditor.mode) {
                    currentMode = window.vditor.vditor.mode;
                } else if (window.vditor.getMode) {
                    currentMode = window.vditor.getMode();
                } else if (window.vditor.getCurrentMode) {
                    currentMode = window.vditor.getCurrentMode();
                }
                
                console.log('Current mode:', currentMode);
                
                console.log('Checking vditor.vditor.redo:', window.vditor.vditor ? typeof window.vditor.vditor.redo : 'vditor.vditor not exist');
                
                // 检查 redo 对象的结构
                if (window.vditor.vditor && window.vditor.vditor.redo) {
                    console.log('vditor.vditor.redo is an object:', typeof window.vditor.vditor.redo === 'object');
                    if (typeof window.vditor.vditor.redo === 'object') {
                        console.log('vditor.vditor.redo keys:', Object.keys(window.vditor.vditor.redo));
                    }
                }
                
                console.log('Checking vditor.vditor.ir.redo:', window.vditor.vditor && window.vditor.vditor.ir ? typeof window.vditor.vditor.ir.redo : 'not exist');
                
                // 直接尝试模拟键盘事件（优先使用，因为键盘快捷键已经正常工作）
                console.log('Trying to simulate keyboard event for redo');
                try {
                    const event = new KeyboardEvent('keydown', {
                        key: 'Z',
                        ctrlKey: true,
                        shiftKey: true,
                        bubbles: true,
                        cancelable: true
                    });
                    
                    // 找到编辑器元素并发送事件
                    if (window.vditor.vditor && window.vditor.vditor.element) {
                        console.log('Dispatching redo event to editor element');
                        window.vditor.vditor.element.dispatchEvent(event);
                        console.log('Redo event dispatched successfully');
                    } else if (window.vditor.vditor && window.vditor.vditor.ir && window.vditor.vditor.ir.element) {
                        console.log('Dispatching redo event to ir element');
                        window.vditor.vditor.ir.element.dispatchEvent(event);
                        console.log('Redo event dispatched successfully');
                    } else {
                        console.log('No suitable element found for event dispatch');
                    }
                } catch (error) {
                    console.error('Error simulating redo event:', error);
                }
                
                // 尝试其他方法作为备份
                console.log('Trying alternative methods');
                
                // 尝试调用顶层方法
                if (typeof window.vditor.redo === 'function') {
                    console.log('Calling window.vditor.redo()');
                    try {
                        window.vditor.redo();
                        console.log('redo() called successfully');
                    } catch (error) {
                        console.error('Error calling window.vditor.redo():', error);
                    }
                }
                
                // 记录最终状态
                console.log('Redo operation completed');
                
                
            }
            closeDrop(); 
        });
        var mobileModeBtn = document.getElementById('mobileModeBtn');
        if (mobileModeBtn) mobileModeBtn.addEventListener('click', function() { showModeSelection(); closeDrop(); });
        var mobileExportBtn = document.getElementById('mobileExportBtn');
        if (mobileExportBtn) mobileExportBtn.addEventListener('click', function() { window.exportContent(); closeDrop(); });
        var mobileClearBtn = document.getElementById('mobileClearBtn');
        if (mobileClearBtn) mobileClearBtn.addEventListener('click', function() {
            if (confirm('确定要清空当前文件的内容吗？')) {
                if (window.vditor) window.vditor.setValue('');
                window.showMessage('内容已清空');
            }
            closeDrop();
        });
        var mobileHelpBtn = document.getElementById('mobileHelpBtn');
        if (mobileHelpBtn) mobileHelpBtn.addEventListener('click', function() { window.open('https://md.yhsun.cn/help', '_blank'); });
        var mobileFormatBtn = document.getElementById('mobileFormatBtn');
        if (mobileFormatBtn) mobileFormatBtn.addEventListener('click', function() { window.showFormatMenu(); });
        var mobileInsertBtn = document.getElementById('mobileInsertBtn');
        if (mobileInsertBtn) mobileInsertBtn.addEventListener('click', function() { window.showInsertMenu(); });
        var mobileSaveBottomBtn = document.getElementById('mobileSaveBottomBtn');
        if (mobileSaveBottomBtn) mobileSaveBottomBtn.addEventListener('click', function() { window.saveCurrentFile(true); });
        var mobileLoginBtn = document.getElementById('mobileLoginBtn');
        if (mobileLoginBtn) mobileLoginBtn.addEventListener('click', window.handleLoginButtonClick);
        var modeToggle = document.getElementById('modeToggle');
        if (modeToggle) modeToggle.addEventListener('click', window.toggleNightMode);
    }

    function showModeSelection() {
        var currentMode = window.vditor && window.vditor.vditor ? window.vditor.vditor.mode : 'ir';
        var nightMode = window.nightMode === true;
        var modeOptions = [
            { icon: '<i class="fas fa-eye"></i>', text: '所见即所得', value: 'wysiwyg', current: currentMode === 'wysiwyg' },
            { icon: '<i class="fas fa-bolt"></i>', text: '即时渲染', value: 'ir', current: currentMode === 'ir' },
            { icon: '<i class="fas fa-columns"></i>', text: '分屏预览', value: 'sv', current: currentMode === 'sv' }
        ];
        var options = modeOptions.map(function(opt) {
            return {
                icon: opt.icon,
                text: opt.text,
                action: function() { setEditorMode(opt.value); }
            };
        });
        window.showMobileActionSheet('选择编辑器模式', options);
    }

    function setEditorMode(mode) {
        if (!window.vditor || !modeMap[mode]) return;
        try {
            var currentContent = window.vditor.getValue();
            localStorage.setItem('vditor_editor_mode', mode);
            if (window.vditor.destroy) window.vditor.destroy();
            var newConfig = {
                height: editorConfig.height,
                width: editorConfig.width,
                placeholder: editorConfig.placeholder,
                toolbar: ['emoji', 'br', 'bold', 'italic', 'strike', '|', 'line', 'quote', 'list', 'ordered-list', 'check', 'outdent', 'indent', 'code', 'inline-code', 'insert-after', 'insert-before', 'upload', 'link', 'table', 'record', 'edit-mode', 'both', 'preview', 'fullscreen', 'outline', 'code-theme', 'content-theme', 'export', 'info', 'help', 'br'],
                customWysiwygToolbar: undefined,
                theme: window.nightMode ? 'dark' : 'classic',
                mode: mode,
                value: currentContent,
                cache: editorConfig.cache,
                outline: editorConfig.outline,
                hint: editorConfig.hint,
                upload: editorConfig.upload,
                after: function() {
                    reinitEditorEvents();
                    reinitMenuEvents();
                    reinitMobileFeatures();
                }
            };
            window.vditor = new Vditor('vditor', newConfig);
            window.showMessage('已切换到' + modeMap[mode].name, 'success');
            var mobileModeBtn = document.getElementById('mobileModeBtn');
            if (mobileModeBtn) mobileModeBtn.innerHTML = '<i class="' + modeMap[mode].icon + '"></i> <span>当前: ' + modeMap[mode].name + '</span>';
        } catch (error) {
            console.error('切换编辑器模式失败', error);
            window.showMessage('切换失败: ' + error.message, 'error');
            window.vditor = new Vditor('vditor', editorConfig);
        }
    }

    function reinitEditorEvents() {
        if (window.vditor && window.vditor.vditor && window.vditor.vditor.ir) {
            window.vditor.vditor.ir.element.addEventListener('input', function() {
                if (window.currentFileId) {
                    window.unsavedChanges[window.currentFileId] = true;
                    window.startAutoSave();
                }
            });
        }
    }

    function reinitMenuEvents() {
        var dropdown = document.getElementById('mobileDropdown');
        function closeDrop() { if (dropdown) dropdown.classList.remove('show'); }

        var list = [

            { id: 'mobileShareBtn', fn: function() { window.showShareDialog(); closeDrop(); } },
            { id: 'mobilePrintBtn', fn: function() { window.showPrintDialog(); closeDrop(); } },
            { id: 'mobileMenuBtn', fn: function(e) { e.stopPropagation(); if (dropdown) dropdown.classList.toggle('show'); } },
            { id: 'mobileModeBtn', fn: function() { showModeSelection(); closeDrop(); } },
            { id: 'mobileExportBtn', fn: function() { window.exportContent(); closeDrop(); } },
            { id: 'mobileClearBtn', fn: function() { if (confirm('确定要清空当前文件的内容吗？')) { if (window.vditor) window.vditor.setValue(''); window.showMessage('内容已清空'); } closeDrop(); } },
            { id: 'mobileHelpBtn', fn: function() { window.open('https://md.yhsun.cn/help', '_blank'); } }
        ];
        list.forEach(function(b) {
            var el = document.getElementById(b.id);
            if (el) {
                var neu = el.cloneNode(true);
                el.parentNode.replaceChild(neu, el);
                neu.addEventListener('click', b.fn);
            }
        });
    }

    function reinitMobileFeatures() {
        var btns = [
            { id: 'mobileFormatBtn', fn: function() { window.showFormatMenu(); } },
            { id: 'mobileInsertBtn', fn: function() { window.showInsertMenu(); } },
            { id: 'mobileFormulaBtn', fn: function() { if (typeof showFormulaPicker === 'function') showFormulaPicker(); } },
            { id: 'mobileChartBtn', fn: function() { window.showChartPicker(); } },
            { id: 'mobileSaveBottomBtn', fn: function() { window.saveCurrentFile(true); } },
            { id: 'mobileLoginBtn', fn: window.handleLoginButtonClick },
            { id: 'modeToggle', fn: window.toggleNightMode },
            { id: 'mobileFileBtn', fn: function() { document.getElementById('fileListSidebar').classList.toggle('show'); } }
        ];
        btns.forEach(function(b) {
            var el = document.getElementById(b.id);
            if (el) {
                var neu = el.cloneNode(true);
                el.parentNode.replaceChild(neu, el);
                neu.addEventListener('click', b.fn);
            }
        });
    }

    var closeHistoryBtn = document.getElementById('closeHistoryBtn');
    if (closeHistoryBtn) closeHistoryBtn.addEventListener('click', function() { var m = document.getElementById('historyModalOverlay'); if (m) m.classList.remove('show'); });
    var historyModalOverlay = document.getElementById('historyModalOverlay');
    if (historyModalOverlay) historyModalOverlay.addEventListener('click', function(e) { if (e.target === this) this.classList.remove('show'); });

    var script0 = document.createElement('script');
    script0.src = 'js/emoji-picker.js';
    document.head.appendChild(script0);
    var script1 = document.createElement('script');
    script1.src = 'js/formula-picker.js';
    document.head.appendChild(script1);

    window.addEventListener('beforeunload', function(e) {
        var unsaved = window.unsavedChanges || {};
        var hasUnsaved = false;
        (window.files || []).forEach(function(file) { if (unsaved[file.id]) hasUnsaved = true; });
        if (hasUnsaved) {
            e.preventDefault();
            e.returnValue = '您有未保存的文件，确定要离开吗？';
            window.saveCurrentFile(true);
            if (window.currentUser) window.syncAllFiles();
        }
    });
});
