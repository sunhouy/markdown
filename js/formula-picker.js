// 公式选择器
function showFormulaPicker() {
    // 增强的LaTeX公式分类
    const formulaCategories = {
        '基础运算': [
            {display: '+', latex: '+'},
            {display: '-', latex: '-'},
            {display: '×', latex: '\\times'},
            {display: '÷', latex: '\\div'},
            {display: '=', latex: '='},
            {display: '≠', latex: '\\neq'},
            {display: '≈', latex: '\\approx'},
            {display: '±', latex: '\\pm'},
            {display: '∓', latex: '\\mp'}
        ],
        '关系符号': [
            {display: '<', latex: '<'},
            {display: '>', latex: '>'},
            {display: '≤', latex: '\\leq'},
            {display: '≥', latex: '\\geq'},
            {display: '≦', latex: '\\leqq'},
            {display: '≧', latex: '\\geqq'},
            {display: '≪', latex: '\\ll'},
            {display: '≫', latex: '\\gg'},
            {display: '≡', latex: '\\equiv'},
            {display: '≢', latex: '\\not\\equiv'}
        ],
        '集合符号': [
            {display: '∈', latex: '\\in'},
            {display: '∉', latex: '\\notin'},
            {display: '⊂', latex: '\\subset'},
            {display: '⊃', latex: '\\supset'},
            {display: '⊆', latex: '\\subseteq'},
            {display: '⊇', latex: '\\supseteq'},
            {display: '∪', latex: '\\cup'},
            {display: '∩', latex: '\\cap'},
            {display: '∅', latex: '\\emptyset'},
            {display: '∞', latex: '\\infty'}
        ],
        '希腊字母': [
            {display: 'α', latex: '\\alpha'},
            {display: 'β', latex: '\\beta'},
            {display: 'γ', latex: '\\gamma'},
            {display: 'δ', latex: '\\delta'},
            {display: 'ε', latex: '\\epsilon'},
            {display: 'ζ', latex: '\\zeta'},
            {display: 'η', latex: '\\eta'},
            {display: 'θ', latex: '\\theta'},
            {display: 'λ', latex: '\\lambda'},
            {display: 'μ', latex: '\\mu'},
            {display: 'ν', latex: '\\nu'},
            {display: 'ξ', latex: '\\xi'},
            {display: 'π', latex: '\\pi'},
            {display: 'ρ', latex: '\\rho'},
            {display: 'σ', latex: '\\sigma'},
            {display: 'τ', latex: '\\tau'},
            {display: 'φ', latex: '\\phi'},
            {display: 'χ', latex: '\\chi'},
            {display: 'ψ', latex: '\\psi'},
            {display: 'ω', latex: '\\omega'}
        ],
        '微积分': [
            {display: '∫', latex: '\\int'},
            {display: '∮', latex: '\\oint'},
            {display: '∬', latex: '\\iint'},
            {display: '∭', latex: '\\iiint'},
            {display: '∂', latex: '\\partial'},
            {display: '∇', latex: '\\nabla'},
            {display: '∆', latex: '\\Delta'},
            {display: '∑', latex: '\\sum'},
            {display: '∏', latex: '\\prod'},
            {display: '∐', latex: '\\coprod'},
            {display: 'lim', latex: '\\lim_{x \\to a} f(x)'},
            {display: 'dx', latex: '\\,dx'},
            {display: 'dy/dx', latex: '\\frac{dy}{dx}'},
            {display: '∫ f(x) dx', latex: '\\int f(x) \\,dx'}
        ],
        '逻辑符号': [
            {display: '∀', latex: '\\forall'},
            {display: '∃', latex: '\\exists'},
            {display: '∄', latex: '\\nexists'},
            {display: '∧', latex: '\\wedge'},
            {display: '∨', latex: '\\vee'},
            {display: '¬', latex: '\\neg'},
            {display: '∴', latex: '\\therefore'},
            {display: '∵', latex: '\\because'},
            {display: '∎', latex: '\\blacksquare'}
        ],
        '箭头符号': [
            {display: '→', latex: '\\to'},
            {display: '←', latex: '\\leftarrow'},
            {display: '↔', latex: '\\leftrightarrow'},
            {display: '↦', latex: '\\mapsto'},
            {display: '⇒', latex: '\\Rightarrow'},
            {display: '⇐', latex: '\\Leftarrow'},
            {display: '⇔', latex: '\\Leftrightarrow'},
            {display: '⇑', latex: '\\Uparrow'},
            {display: '⇓', latex: '\\Downarrow'}
        ],
        '几何符号': [
            {display: '∠', latex: '\\angle'},
            {display: '⊥', latex: '\\perp'},
            {display: '∥', latex: '\\parallel'},
            {display: '≅', latex: '\\cong'},
            {display: '∼', latex: '\\sim'},
            {display: '∽', latex: '\\backsim'},
            {display: '∝', latex: '\\propto'},
            {display: '∘', latex: '\\circ'},
            {display: '•', latex: '\\bullet'}
        ],
        '分数指数': [
            {display: '½', latex: '\\frac{1}{2}'},
            {display: '⅓', latex: '\\frac{1}{3}'},
            {display: '¼', latex: '\\frac{1}{4}'},
            {display: '√', latex: '\\sqrt{}'},
            {display: '∛', latex: '\\sqrt[3]{}'},
            {display: '∜', latex: '\\sqrt[4]{}'},
            {display: 'ⁿ', latex: '^{n}'},
            {display: 'a/b', latex: '\\frac{a}{b}'},
            {display: 'aⁿ', latex: 'a^{n}'},
            {display: '√a', latex: '\\sqrt{a}'}
        ],
        '线性代数': [
            {display: 'Aᵀ', latex: 'A^{T}'},
            {display: 'det(A)', latex: '\\det(A)'},
            {display: 'tr(A)', latex: '\\operatorname{tr}(A)'},
            {display: 'rank(A)', latex: '\\operatorname{rank}(A)'},
            {display: 'Iₙ', latex: 'I_{n}'},
            {display: '0ₙ', latex: '\\mathbf{0}_{n}'},
            {display: 'u·v', latex: '\\mathbf{u} \\cdot \\mathbf{v}'},
            {display: 'u×v', latex: '\\mathbf{u} \\times \\mathbf{v}'},
            {display: '‖v‖', latex: '\\|\\mathbf{v}\\|'},
            {display: '矩阵', latex: '\\begin{pmatrix} a & b \\\\ c & d \\end{pmatrix}'},
            {display: '行列式', latex: '\\begin{vmatrix} a & b \\\\ c & d \\end{vmatrix}'},
            {display: '向量', latex: '\\begin{bmatrix} x \\\\ y \\\\ z \\end{bmatrix}'}
        ],
        '化学符号': [
            {display: '→', latex: '\\rightarrow'},
            {display: '⇌', latex: '\\rightleftharpoons'},
            {display: '↑', latex: '\\uparrow'},
            {display: '↓', latex: '\\downarrow'},
            {display: 'H₂O', latex: '\\mathrm{H_2O}'},
            {display: 'CO₂', latex: '\\mathrm{CO_2}'},
            {display: 'H⁺', latex: '\\mathrm{H^+}'},
            {display: 'OH⁻', latex: '\\mathrm{OH^-}'},
            {display: 'ΔH', latex: '\\Delta H'},
            {display: '⇌ 平衡', latex: '\\mathrm{A} + \\mathrm{B} \\rightleftharpoons \\mathrm{C}'},
            {display: '→ 反应', latex: '2\\mathrm{H_2} + \\mathrm{O_2} \\rightarrow 2\\mathrm{H_2O}'}
        ],
        '函数运算': [
            {display: 'sin', latex: '\\sin'},
            {display: 'cos', latex: '\\cos'},
            {display: 'tan', latex: '\\tan'},
            {display: 'log', latex: '\\log'},
            {display: 'ln', latex: '\\ln'},
            {display: 'exp', latex: '\\exp'},
            {display: 'max', latex: '\\max'},
            {display: 'min', latex: '\\min'},
            {display: 'argmax', latex: '\\arg\\max'},
            {display: 'argmin', latex: '\\arg\\min'}
        ],
        '括号': [
            {display: '( )', latex: '()'},
            {display: '[ ]', latex: '[]'},
            {display: '{ }', latex: '\\{\\}'},
            {display: '⟨ ⟩', latex: '\\langle \\rangle'},
            {display: '⌊ ⌋', latex: '\\lfloor \\rfloor'},
            {display: '⌈ ⌉', latex: '\\lceil \\rceil'},
            {display: '∣ ∣', latex: '| |'},
            {display: '∥ ∥', latex: '\\| \\|'}
        ],
        '上下标': [
            {display: 'a₁', latex: 'a_{1}'},
            {display: 'x²', latex: 'x^{2}'},
            {display: 'x̄', latex: '\\bar{x}'},
            {display: 'x̂', latex: '\\hat{x}'},
            {display: 'x̃', latex: '\\tilde{x}'},
            {display: 'ẋ', latex: '\\dot{x}'},
            {display: 'ẍ', latex: '\\ddot{x}'},
            {display: 'Aᵢⱼ', latex: 'A_{ij}'}
        ],
        '特殊符号': [
            {display: 'ℕ', latex: '\\mathbb{N}'},
            {display: 'ℤ', latex: '\\mathbb{Z}'},
            {display: 'ℚ', latex: '\\mathbb{Q}'},
            {display: 'ℝ', latex: '\\mathbb{R}'},
            {display: 'ℂ', latex: '\\mathbb{C}'},
            {display: 'ℙ', latex: '\\mathbb{P}'},
            {display: '𝔼', latex: '\\mathbb{E}'},
            {display: '∇·', latex: '\\nabla \\cdot'},
            {display: '∇×', latex: '\\nabla \\times'},
            {display: '□', latex: '\\Box'}
        ],
        '常用公式模板': [
            {display: '二次公式', latex: 'x = \\frac{-b \\pm \\sqrt{b^2 - 4ac}}{2a}'},
            {display: '欧拉公式', latex: 'e^{i\\theta} = \\cos\\theta + i\\sin\\theta'},
            {display: '分部积分', latex: '\\int u \\, dv = uv - \\int v \\, du'},
            {display: '链式法则', latex: '\\frac{dy}{dx} = \\frac{dy}{du} \\cdot \\frac{du}{dx}'},
            {display: '傅里叶变换', latex: 'F(\\omega) = \\int_{-\\infty}^{\\infty} f(t) e^{-i\\omega t} dt'},
            {display: '薛定谔方程', latex: 'i\\hbar\\frac{\\partial}{\\partial t}\\Psi = \\hat{H}\\Psi'}
        ]
    };

    // 创建公式选择器界面
    const formulaSheet = document.createElement('div');
    formulaSheet.className = 'formula-picker-modal';
    formulaSheet.style.cssText = `
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

    // 创建选择器容器
    const formulaContainer = document.createElement('div');
    formulaContainer.style.cssText = `
        background: ${(window.nightMode === true) ? '#2d2d2d' : 'white'};
        border-radius: 12px;
        padding: 20px;
        width: 90%;
        max-width: 600px;
        max-height: 80vh;
        overflow: hidden;
        display: flex;
        flex-direction: column;
    `;

    // 创建标题
    const title = document.createElement('div');
    title.textContent = '插入LaTeX公式';
    title.style.cssText = `
        font-size: 18px;
        font-weight: 600;
        margin-bottom: 15px;
        text-align: center;
        color: ${(window.nightMode === true) ? '#eee' : '#333'};
    `;
    formulaContainer.appendChild(title);

    // 创建分类标签
    const categoryTabs = document.createElement('div');
    categoryTabs.style.cssText = `
        display: flex;
        overflow-x: auto;
        padding: 10px 0;
        margin-bottom: 10px;
        border-bottom: 1px solid ${(window.nightMode === true) ? '#444' : '#eee'};
    `;

    // 创建符号网格容器
    const formulaGrid = document.createElement('div');
    formulaGrid.id = 'formulaGrid';
    formulaGrid.style.cssText = `
        display: grid;
        grid-template-columns: repeat(5, 1fr);
        gap: 8px;
        padding: 10px 0;
        overflow-y: auto;
        max-height: 300px;
        flex: 1;
    `;
    formulaContainer.appendChild(formulaGrid);

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
    insertBtn.textContent = '插入LaTeX';
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

    const wrapInDollarBtn = document.createElement('button');
    wrapInDollarBtn.textContent = '插入行内公式';
    wrapInDollarBtn.style.cssText = `
        padding: 10px 20px;
        background: #4a90e2;
        color: white;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-size: 14px;
        margin-left: 10px;
    `;

    const wrapInDoubleDollarBtn = document.createElement('button');
    wrapInDoubleDollarBtn.textContent = '插入多行公式';
    wrapInDoubleDollarBtn.style.cssText = `
        padding: 10px 20px;
        background: #4a90e2;
        color: white;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-size: 14px;
        margin-left: 10px;
    `;

    const buttonGroup = document.createElement('div');
    buttonGroup.style.cssText = `
        display: flex;
        flex-wrap: wrap;
        gap: 5px;
    `;
    buttonGroup.appendChild(insertBtn);
    buttonGroup.appendChild(wrapInDollarBtn);
    buttonGroup.appendChild(wrapInDoubleDollarBtn);

    bottomBar.appendChild(buttonGroup);
    bottomBar.appendChild(cancelBtn);
    formulaContainer.appendChild(bottomBar);

    formulaSheet.appendChild(formulaContainer);
    document.body.appendChild(formulaSheet);

    let selectedFormula = null;

    // 创建分类标签
    Object.keys(formulaCategories).forEach(category => {
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
            font-size: 12px;
        `;
        tab.addEventListener('click', () => {
            // 移除所有标签的激活状态
            document.querySelectorAll('.formula-tab').forEach(t => {
                t.style.background = (window.nightMode === true) ? '#444' : '#f5f5f5';
                t.style.color = (window.nightMode === true) ? '#eee' : '#333';
                t.style.fontWeight = 'normal';
            });
            // 激活当前标签
            tab.style.background = '#4a90e2';
            tab.style.color = 'white';
            tab.style.fontWeight = '600';
            // 显示对应的符号
            showFormulaCategory(category);
        });
        tab.className = 'formula-tab';
        categoryTabs.appendChild(tab);
    });

    formulaContainer.insertBefore(categoryTabs, formulaGrid);

    // 显示第一个分类的符号
    const firstTab = categoryTabs.querySelector('.formula-tab');
    if (firstTab) {
        firstTab.click();
    }

    // 显示指定分类的符号
    function showFormulaCategory(category) {
        formulaGrid.innerHTML = '';
        selectedFormula = null;

        formulaCategories[category].forEach(item => {
            const symbolBtn = document.createElement('button');
            symbolBtn.innerHTML = `<span style="font-size: 16px;">${item.display}</span>`;
            symbolBtn.title = `LaTeX: ${item.latex}`;
            symbolBtn.style.cssText = `
                padding: 10px;
                border: 2px solid transparent;
                background: none;
                cursor: pointer;
                border-radius: 6px;
                transition: all 0.2s;
                display: flex;
                align-items: center;
                justify-content: center;
                font-family: 'Times New Roman', serif;
                color: ${(window.nightMode === true) ? '#fff' : '#333'};
                text-align: center;
                word-break: break-all;
                min-height: 60px;
                flex-direction: column;
            `;

            // 添加LaTeX代码提示
            const latexPreview = document.createElement('div');
            latexPreview.textContent = item.latex;
            latexPreview.style.cssText = `
                font-size: 10px;
                color: ${(window.nightMode === true) ? '#aaa' : '#666'};
                margin-top: 5px;
                font-family: 'Courier New', monospace;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                max-width: 100%;
            `;
            symbolBtn.appendChild(latexPreview);

            symbolBtn.addEventListener('click', () => {
                // 移除所有符号的选中状态
                document.querySelectorAll('#formulaGrid button').forEach(btn => {
                    btn.style.borderColor = 'transparent';
                    btn.style.background = 'none';
                });

                // 设置当前符号为选中状态
                symbolBtn.style.borderColor = '#4a90e2';
                symbolBtn.style.background = (window.nightMode === true) ? 'rgba(74, 144, 226, 0.2)' : 'rgba(74, 144, 226, 0.1)';
                selectedFormula = item;
            });

            symbolBtn.addEventListener('mouseenter', function() {
                if (selectedFormula !== item) {
                    this.style.background = (window.nightMode === true) ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.05)';
                }
            });

            symbolBtn.addEventListener('mouseleave', function() {
                if (selectedFormula !== item) {
                    this.style.background = 'none';
                }
            });

            formulaGrid.appendChild(symbolBtn);
        });
    }

    // 插入按钮点击事件
    insertBtn.addEventListener('click', () => {
        if (selectedFormula && vditor) {
            vditor.insertValue(selectedFormula.latex + '\n\n');
            closeFormulaPicker();
            showMessage('LaTeX公式已插入');
        } else {
            showMessage('请先选择一个公式', 'error');
        }
    });

    // 插入带$的公式
    wrapInDollarBtn.addEventListener('click', () => {
        if (selectedFormula && vditor) {
            vditor.insertValue(`$${selectedFormula.latex}$` + '\n\n');
            closeFormulaPicker();
            showMessage('行内公式已插入');
        } else {
            showMessage('请先选择一个公式', 'error');
        }
    });

    // 插入带$$的公式
    wrapInDoubleDollarBtn.addEventListener('click', () => {
        if (selectedFormula && vditor) {
            vditor.insertValue(`$$${selectedFormula.latex}$$` + '\n\n');
            closeFormulaPicker();
            showMessage('块级公式已插入');
        } else {
            showMessage('请先选择一个公式', 'error');
        }
    });

    // 取消按钮点击事件
    cancelBtn.addEventListener('click', closeFormulaPicker);

    // 点击模态框外部关闭
    formulaSheet.addEventListener('click', (e) => {
        if (e.target === formulaSheet) {
            closeFormulaPicker();
        }
    });

    // 关闭公式选择器
    function closeFormulaPicker() {
        if (formulaSheet.parentNode) {
            formulaSheet.parentNode.removeChild(formulaSheet);
        }
    }

    // 添加键盘事件支持
    const handleKeydown = function(e) {
        if (e.key === 'Escape') {
            closeFormulaPicker();
            document.removeEventListener('keydown', handleKeydown);
        }
    };
    document.addEventListener('keydown', handleKeydown);
}