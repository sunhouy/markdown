function showEmojiPicker() {
    // 表情分类
    const emojiCategories = {
        '常用': ['😀', '😁', '😂', '😃', '😄', '😅', '😆', '😉', '😊', '😋', '😎', '😍', '😘', '😗', '😙', '😚', '🙂', '🤗', '🤔', '😐', '😑', '😶', '🙄', '😏', '😣', '😥', '😮', '🤐', '😯', '😪', '😫', '😴', '😌', '😛', '😜', '😝', '🤤', '😒', '😓', '😔', '😕', '🙃', '🤑', '😲', '☹️', '🙁', '😖', '😞', '😟', '😤', '😢', '😭', '😦', '😧', '😨', '😩', '🤯', '😬', '😰', '😱', '😳', '🤪', '😵', '😡', '😠', '🤬', '😷', '🤒', '🤕', '🤢', '🤮', '🤧', '😇', '🤠', '🤡', '🤥', '🤫', '🤭', '🧐', '🤓', '😈', '👿', '👹', '👺', '💀', '👻', '👽', '🤖', '💩', '😺', '😸', '😹', '😻', '😼', '😽', '🙀', '😿', '😾'],
        '手势': ['👏', '🙌', '👐', '🤲', '🤝', '👍', '👎', '👊', '✊', '🤛', '🤜', '🤞', '✌️', '🤟', '🤘', '👌', '🤏', '👈', '👉', '👆', '👇', '☝️', '✋', '🤚', '🖐️', '🖖', '👋', '🤙', '💪', '🦾', '🖕', '✍️', '🙏'],
        '物品': ['⌚', '📱', '📲', '💻', '⌨️', '🖥️', '🖨️', '🖱️', '🖲️', '🎮', '🕹️', '🗜️', '💾', '💿', '📀', '📼', '📷', '📸', '📹', '🎥', '📽️', '🎞️', '📞', '☎️', '📟', '📠', '📺', '📻', '🎙️', '🎚️', '🎛️', '🧭', '⏱️', '⏲️', '⏰', '🕰️', '⌛', '⏳', '📡', '🔋', '🔌', '💡', '🔦', '🕯️', '🪔', '🧯', '🛢️', '💸', '💵', '💴', '💶', '💷', '💰', '💳', '💎'],
        '符号': ['❤️', '🧡', '💛', '💚', '💙', '💜', '🖤', '🤍', '🤎', '💔', '❣️', '💕', '💞', '💓', '💗', '💖', '💘', '💝', '💟', '☮️', '✝️', '☪️', '🕉️', '☸️', '✡️', '🔯', '🕎', '☯️', '☦️', '🛐', '⛎', '♈', '♉', '♊', '♋', '♌', '♍', '♎', '♏', '♐', '♑', '♒', '♓', '🆔', '⚛️', '🉑', '☢️', '☣️', '📴', '📳', '🈶', '🈚', '🈸', '🈺', '🈷️', '✴️', '🆚', '💮', '🉐', '㊙️', '㊗️', '🈴', '🈵', '🈹', '🈲', '🅰️', '🅱️', '🆎', '🆑', '🅾️', '🆘', '❌', '⭕', '🛑', '⛔', '📛', '🚫', '💯', '💢', '♨️', '🚷', '🚯', '🚳', '🚱', '🔞', '📵', '🚭']
    };

    // 创建表情选择器界面
    const emojiSheet = document.createElement('div');
    emojiSheet.className = 'emoji-picker-modal';
    emojiSheet.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.7);
        z-index: 2000;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
    `;

    // 创建表情选择器容器
    const emojiContainer = document.createElement('div');
    emojiContainer.style.cssText = `
        background: ${(window.nightMode === true) ? '#2d2d2d' : 'white'};
        border-radius: 12px;
        padding: 20px;
        width: 90%;
        max-width: 500px;
        max-height: 80vh;
        overflow: hidden;
        display: flex;
        flex-direction: column;
    `;

    // 创建标题
    const title = document.createElement('div');
    title.textContent = '选择表情';
    title.style.cssText = `
        font-size: 18px;
        font-weight: 600;
        margin-bottom: 15px;
        text-align: center;
        color: ${(window.nightMode === true) ? '#eee' : '#333'};
    `;
    emojiContainer.appendChild(title);

    // 创建分类标签
    const categoryTabs = document.createElement('div');
    categoryTabs.style.cssText = `
        display: flex;
        overflow-x: auto;
        padding: 10px 0;
        margin-bottom: 10px;
        border-bottom: 1px solid ${(window.nightMode === true) ? '#444' : '#eee'};
    `;

    Object.keys(emojiCategories).forEach(category => {
        const tab = document.createElement('button');
        tab.textContent = category;
        tab.style.cssText = `
            padding: 8px 12px;
            margin-right: 10px;
            border: none;
            background: ${(window.nightMode === true) ? '#444' : '#f5f5f5'};
            border-radius: 20px;
            white-space: nowrap;
            cursor: pointer;
            color: ${(window.nightMode === true) ? '#eee' : '#333'};
        `;
        tab.addEventListener('click', () => {
            // 移除所有标签的激活状态
            document.querySelectorAll('.emoji-tab').forEach(t => {
                t.style.background = (window.nightMode === true) ? '#444' : '#f5f5f5';
                t.style.fontWeight = 'normal';
            });
            // 激活当前标签
            tab.style.background = '#4a90e2';
            tab.style.color = 'white';
            tab.style.fontWeight = '600';
            // 显示对应的表情
            showEmojiCategory(category);
        });
        tab.className = 'emoji-tab';
        categoryTabs.appendChild(tab);
    });

    emojiContainer.appendChild(categoryTabs);

    // 创建表情网格容器
    const emojiGrid = document.createElement('div');
    emojiGrid.id = 'emojiGrid';
    emojiGrid.style.cssText = `
        display: grid;
        grid-template-columns: repeat(8, 1fr);
        gap: 8px;
        padding: 10px 0;
        overflow-y: auto;
        max-height: 300px;
        flex: 1;
    `;
    emojiContainer.appendChild(emojiGrid);

    // 创建底部按钮
    const bottomBar = document.createElement('div');
    bottomBar.style.cssText = `
        display: flex;
        justify-content: space-between;
        margin-top: 15px;
        padding-top: 15px;
        border-top: 1px solid ${(window.nightMode === true) ? '#444' : '#eee'};
    `;

    const insertBtn = document.createElement('button');
    insertBtn.textContent = '插入选中的表情';
    insertBtn.style.cssText = `
        padding: 10px 20px;
        background: #4a90e2;
        color: white;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-size: 14px;
    `;

    const cancelBtn = document.createElement('button');
    cancelBtn.textContent = '取消';
    cancelBtn.style.cssText = `
        padding: 10px 20px;
        background: ${(window.nightMode === true) ? '#444' : '#f5f5f5'};
        color: ${(window.nightMode === true) ? '#eee' : '#333'};
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-size: 14px;
    `;

    bottomBar.appendChild(insertBtn);
    bottomBar.appendChild(cancelBtn);
    emojiContainer.appendChild(bottomBar);

    emojiSheet.appendChild(emojiContainer);
    document.body.appendChild(emojiSheet);

    let selectedEmoji = '';

    // 显示第一个分类的表情
    const firstTab = categoryTabs.querySelector('.emoji-tab');
    if (firstTab) {
        firstTab.click();
    }

    // 显示指定分类的表情
    function showEmojiCategory(category) {
        emojiGrid.innerHTML = '';
        selectedEmoji = '';

        emojiCategories[category].forEach(emoji => {
            const emojiBtn = document.createElement('button');
            emojiBtn.textContent = emoji;
            emojiBtn.style.cssText = `
                font-size: 24px;
                padding: 12px;
                border: 2px solid transparent;
                background: none;
                cursor: pointer;
                border-radius: 8px;
                transition: all 0.2s;
                display: flex;
                align-items: center;
                justify-content: center;
            `;

            emojiBtn.addEventListener('click', () => {
                // 移除所有表情的选中状态
                document.querySelectorAll('#emojiGrid button').forEach(btn => {
                    btn.style.borderColor = 'transparent';
                    btn.style.background = 'none';
                });

                // 设置当前表情为选中状态
                emojiBtn.style.borderColor = '#4a90e2';
                emojiBtn.style.background = (window.nightMode === true) ? 'rgba(74, 144, 226, 0.2)' : 'rgba(74, 144, 226, 0.1)';
                selectedEmoji = emoji;
            });

            emojiBtn.addEventListener('mouseenter', function() {
                if (selectedEmoji !== emoji) {
                    this.style.background = (window.nightMode === true) ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.05)';
                }
            });

            emojiBtn.addEventListener('mouseleave', function() {
                if (selectedEmoji !== emoji) {
                    this.style.background = 'none';
                }
            });

            emojiGrid.appendChild(emojiBtn);
        });
    }

    // 插入按钮点击事件
    insertBtn.addEventListener('click', () => {
        if (selectedEmoji && vditor) {
            vditor.insertValue(selectedEmoji);
            closeEmojiPicker();
            showMessage('表情已插入');
        } else {
            showMessage('请先选择一个表情', 'error');
        }
    });

    // 取消按钮点击事件
    cancelBtn.addEventListener('click', closeEmojiPicker);

    // 点击模态框外部关闭
    emojiSheet.addEventListener('click', (e) => {
        if (e.target === emojiSheet) {
            closeEmojiPicker();
        }
    });

    // 关闭表情选择器
    function closeEmojiPicker() {
        if (emojiSheet.parentNode) {
            emojiSheet.parentNode.removeChild(emojiSheet);
        }
    }

    // 添加键盘事件支持
    document.addEventListener('keydown', function handleKeydown(e) {
        if (e.key === 'Escape') {
            closeEmojiPicker();
            document.removeEventListener('keydown', handleKeydown);
        }
    });
}