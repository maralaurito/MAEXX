/**
 * MAEXX — live stock validation for forms that take stock out
 * (Inventory "Stock Out" and Sales "Record Customer Order").
 *
 * Shows, as the user types, what the transaction does to the product:
 *
 *   over    quantity is more than what is on hand  -> blocked
 *   empty   quantity uses up every last unit       -> must confirm
 *   below   leaves the product under its minimum   -> must confirm
 *   ok      stock stays at or above the minimum
 *
 * The product <select> options must carry:
 *   data-stock, data-threshold, data-unit, data-name
 *
 * The server repeats these checks, so this is guidance, not the only
 * line of defence.
 */
(function () {

    const STYLE = `
    .sg-panel { margin-top: 10px; border-radius: 12px; border: 1px solid #e2e8f0;
                background: #f8fafc; overflow: hidden; font-size: 12.5px; display: none; }
    .sg-panel.show { display: block; }
    .sg-stats { display: grid; grid-template-columns: repeat(3, 1fr); }
    .sg-stat { padding: 9px 12px; border-right: 1px solid #e2e8f0; }
    .sg-stat:last-child { border-right: none; }
    .sg-stat .k { font-size: 10.5px; color: #94a3b8; text-transform: uppercase; letter-spacing: .6px; font-weight: 600; }
    .sg-stat .v { font-size: 14px; font-weight: 700; color: #0f172a; margin-top: 1px; }
    .sg-bar { height: 5px; background: #e2e8f0; }
    .sg-bar span { display: block; height: 100%; width: 0; transition: width .25s ease, background .25s ease; }
    .sg-msg { display: flex; gap: 9px; align-items: flex-start; padding: 10px 12px; line-height: 1.5; border-top: 1px solid #e2e8f0; }
    .sg-msg i { font-size: 15px; line-height: 1.3; }
    .sg-msg b { font-weight: 700; }
    .sg-ack { display: none; gap: 8px; align-items: flex-start; padding: 9px 12px 11px;
              border-top: 1px dashed rgba(0,0,0,.08); font-size: 12px; cursor: pointer; user-select: none; }
    .sg-ack input { margin-top: 2px; width: 15px; height: 15px; cursor: pointer; flex: 0 0 auto; }

    .sg-panel.state-ok    { border-color: #bbf7d0; }
    .sg-panel.state-ok    .sg-msg { background: #f0fdf4; color: #166534; }
    .sg-panel.state-below { border-color: #fde68a; }
    .sg-panel.state-below .sg-msg, .sg-panel.state-below .sg-ack { background: #fffbeb; color: #92400e; }
    .sg-panel.state-empty { border-color: #fecaca; }
    .sg-panel.state-empty .sg-msg, .sg-panel.state-empty .sg-ack { background: #fef2f2; color: #991b1b; }
    .sg-panel.state-over  { border-color: #fca5a5; }
    .sg-panel.state-over  .sg-msg { background: #fef2f2; color: #b91c1c; }
    .sg-panel.state-below .sg-ack, .sg-panel.state-empty .sg-ack { display: flex; }

    button.sg-blocked, button.sg-blocked:hover {
        opacity: .5; cursor: not-allowed; transform: none !important; filter: grayscale(.3);
    }
    .sg-shake { animation: sg-shake .35s ease; }
    @keyframes sg-shake { 0%,100%{transform:translateX(0)} 25%{transform:translateX(-5px)} 75%{transform:translateX(5px)} }
    input.sg-invalid { border-color: #dc2626 !important; box-shadow: 0 0 0 3px rgba(220,38,38,.12) !important; }
    `;

    function injectStyles() {
        if (document.getElementById('sg-styles')) return;
        const tag = document.createElement('style');
        tag.id = 'sg-styles';
        tag.textContent = STYLE;
        document.head.appendChild(tag);
    }

    /**
     * Work out what a quantity does to the selected product.
     */
    function assess(opt, qty) {
        const stock     = parseInt(opt.dataset.stock, 10) || 0;
        const threshold = parseInt(opt.dataset.threshold, 10) || 0;
        const unit      = opt.dataset.unit || 'pcs';
        const name      = opt.dataset.name || opt.text.split('(')[0].trim();
        const remaining = stock - qty;

        let state = 'ok';
        if (qty > stock)             state = 'over';
        else if (remaining === 0)    state = 'empty';
        else if (remaining < threshold) state = 'below';

        return { stock, threshold, unit, name, qty, remaining, state };
    }

    function message(a) {
        switch (a.state) {
            case 'over':
                return {
                    icon: 'bi-x-octagon-fill',
                    html: `<b>Not enough stock.</b> Only <b>${a.stock} ${a.unit}</b> of ${escapeHtml(a.name)} on hand — ` +
                          `this is <b>${a.qty - a.stock} ${a.unit}</b> too many. Reduce the quantity` +
                          (a.stock > 0 ? ` to ${a.stock} or less.` : ', or restock first.')
                };
            case 'empty':
                return {
                    icon: 'bi-exclamation-octagon-fill',
                    html: `<b>This uses up all remaining stock.</b> ${escapeHtml(a.name)} will be <b>Out of Stock</b> ` +
                          `and cannot be ordered again until it is restocked.`
                };
            case 'below':
                return {
                    icon: 'bi-exclamation-triangle-fill',
                    html: `<b>Stock will fall below the minimum.</b> Only <b>${a.remaining} ${a.unit}</b> will be left, ` +
                          `<b>${a.threshold - a.remaining} ${a.unit}</b> short of the ${a.threshold} ${a.unit} minimum. ` +
                          `Plan a restock soon.`
                };
            default:
                return {
                    icon: 'bi-check-circle-fill',
                    html: `Stock stays healthy — <b>${a.remaining} ${a.unit}</b> left, ` +
                          `${a.remaining - a.threshold} ${a.unit} above the minimum.`
                };
        }
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
    }

    /**
     * Wire a form up.
     *
     * @param {object} cfg { form, select, quantity, submit }  (elements or ids)
     */
    function attach(cfg) {
        injectStyles();

        const $ = x => typeof x === 'string' ? document.getElementById(x) : x;
        const form   = $(cfg.form);
        const select = $(cfg.select);
        const qtyEl  = $(cfg.quantity);
        const submit = $(cfg.submit);
        if (!form || !select || !qtyEl || !submit) return;

        const panel = document.createElement('div');
        panel.className = 'sg-panel';
        panel.innerHTML = `
            <div class="sg-stats">
                <div class="sg-stat"><div class="k">On hand</div><div class="v" data-sg="stock">—</div></div>
                <div class="sg-stat"><div class="k">After this</div><div class="v" data-sg="after">—</div></div>
                <div class="sg-stat"><div class="k">Minimum</div><div class="v" data-sg="min">—</div></div>
            </div>
            <div class="sg-bar"><span data-sg="bar"></span></div>
            <div class="sg-msg"><i class="bi" data-sg="icon"></i><div data-sg="text"></div></div>
            <label class="sg-ack">
                <input type="checkbox" name="confirm_low" value="1" data-sg="ack">
                <span data-sg="acktext">I understand and want to proceed anyway.</span>
            </label>`;

        // Place the panel after the quantity field (and any hint under it).
        const anchor = qtyEl.parentElement;
        anchor.appendChild(panel);

        const el    = key => panel.querySelector(`[data-sg="${key}"]`);
        const ack   = el('ack');
        const label = submit.innerHTML;

        let current = null;

        function block(isBlocked) {
            submit.disabled = isBlocked;
            submit.classList.toggle('sg-blocked', isBlocked);
        }

        function update() {
            const opt = select.options[select.selectedIndex];
            const qty = parseInt(qtyEl.value, 10) || 0;

            if (!opt || !opt.value) {
                panel.classList.remove('show');
                qtyEl.classList.remove('sg-invalid');
                qtyEl.removeAttribute('max');
                block(false);
                submit.innerHTML = label;
                current = null;
                return;
            }

            const a = assess(opt, qty);
            qtyEl.max = a.stock;

            el('stock').textContent = `${a.stock} ${a.unit}`;
            el('min').textContent   = `${a.threshold} ${a.unit}`;

            // Nothing typed yet: show the product's situation only.
            if (qty <= 0) {
                panel.className = 'sg-panel show ' + (a.stock === 0 ? 'state-over' : a.stock < a.threshold ? 'state-below' : 'state-ok');
                el('after').textContent = '—';
                el('bar').style.width = '0';
                el('icon').className = 'bi ' + (a.stock < a.threshold ? 'bi-exclamation-triangle-fill' : 'bi-info-circle-fill');
                el('text').innerHTML = a.stock === 0
                    ? `<b>${escapeHtml(a.name)} is out of stock.</b> Restock it before issuing or selling.`
                    : a.stock < a.threshold
                        ? `<b>${escapeHtml(a.name)} is already low</b> — ${a.stock} ${a.unit} left, below the ${a.threshold} ${a.unit} minimum.`
                        : `Enter a quantity to check it against stock.`;
                panel.querySelector('.sg-ack').style.display = 'none';
                ack.checked = false;
                qtyEl.classList.remove('sg-invalid');
                block(a.stock === 0);
                submit.innerHTML = a.stock === 0
                    ? '<i class="bi bi-slash-circle"></i> Out of stock'
                    : label;
                current = null;
                return;
            }
            panel.querySelector('.sg-ack').style.display = '';

            el('after').textContent = a.state === 'over' ? `−${a.qty - a.stock} ${a.unit}` : `${a.remaining} ${a.unit}`;
            el('after').style.color = a.state === 'ok' ? '#166534' : a.state === 'below' ? '#b45309' : '#b91c1c';

            // Bar: share of current stock that this transaction consumes.
            const used = a.stock > 0 ? Math.min(100, Math.round((a.qty / a.stock) * 100)) : 100;
            el('bar').style.width = used + '%';
            el('bar').style.background = { ok: '#22c55e', below: '#f59e0b', empty: '#ef4444', over: '#dc2626' }[a.state];

            const m = message(a);
            el('icon').className = 'bi ' + m.icon;
            el('text').innerHTML = m.html;

            if (current !== a.state) ack.checked = false;   // re-confirm when the risk changes
            el('acktext').textContent = a.state === 'empty'
                ? 'I understand this will leave the product out of stock.'
                : 'I understand stock will fall below the minimum.';

            panel.className = 'sg-panel show state-' + a.state;
            qtyEl.classList.toggle('sg-invalid', a.state === 'over');

            block(a.state === 'over' || ((a.state === 'below' || a.state === 'empty') && !ack.checked));
            submit.innerHTML = a.state === 'over'
                ? '<i class="bi bi-slash-circle"></i> Not enough stock'
                : label;

            current = a.state;
        }

        select.addEventListener('change', update);
        qtyEl.addEventListener('input', update);
        ack.addEventListener('change', update);

        // Last check at submit time, in case something slipped through.
        form.addEventListener('submit', e => {
            update();
            if (submit.disabled) {
                e.preventDefault();
                panel.classList.remove('sg-shake');
                void panel.offsetWidth;
                panel.classList.add('sg-shake');
                (current === 'over' ? qtyEl : ack).focus();
            }
        });

        // Reset when the modal is reopened.
        form.addEventListener('reset', () => setTimeout(update, 0));
        const modal = form.closest('.modal');
        if (modal) modal.addEventListener('show.bs.modal', () => setTimeout(update, 0));

        update();
    }

    /**
     * Delivery variant (Sales "Confirm Delivery").
     *
     * Stock was already deducted when the order was recorded, so at
     * delivery the question is how the delivered quantity compares with
     * what was ordered:
     *
     *   over     more than ordered — never taken out of stock -> blocked
     *   short    partial delivery — the rest returns to stock -> must confirm
     *   ok       exactly as ordered
     *   invalid  zero or blank                                -> blocked
     *
     * @param {object} cfg { form, quantity, submit }
     * @returns {{ load(order): void }}  call load() each time the modal opens
     *   order = { ordered, unit, name, stock }  (stock = current on-hand)
     */
    function attachDelivery(cfg) {
        injectStyles();

        const $ = x => typeof x === 'string' ? document.getElementById(x) : x;
        const form   = $(cfg.form);
        const qtyEl  = $(cfg.quantity);
        const submit = $(cfg.submit);
        if (!form || !qtyEl || !submit) return { load() {} };

        const panel = document.createElement('div');
        panel.className = 'sg-panel';
        panel.innerHTML = `
            <div class="sg-stats">
                <div class="sg-stat"><div class="k">Ordered</div><div class="v" data-sg="ordered">—</div></div>
                <div class="sg-stat"><div class="k">Delivering</div><div class="v" data-sg="delivering">—</div></div>
                <div class="sg-stat"><div class="k">Difference</div><div class="v" data-sg="diff">—</div></div>
            </div>
            <div class="sg-bar"><span data-sg="bar"></span></div>
            <div class="sg-msg"><i class="bi" data-sg="icon"></i><div data-sg="text"></div></div>
            <label class="sg-ack">
                <input type="checkbox" name="confirm_short" value="1" data-sg="ack">
                <span data-sg="acktext"></span>
            </label>`;
        qtyEl.parentElement.appendChild(panel);

        const el    = key => panel.querySelector(`[data-sg="${key}"]`);
        const ack   = el('ack');
        const label = submit.innerHTML;
        let order   = null;
        let current = null;

        function block(isBlocked) {
            submit.disabled = isBlocked;
            submit.classList.toggle('sg-blocked', isBlocked);
        }

        function update() {
            if (!order) return;

            const qty  = parseInt(qtyEl.value, 10) || 0;
            const unit = order.unit;
            const diff = qty - order.ordered;

            let state = 'ok';
            if (qty <= 0)                 state = 'invalid';
            else if (qty > order.ordered) state = 'over';
            else if (qty < order.ordered) state = 'short';

            el('ordered').textContent    = `${order.ordered} ${unit}`;
            el('delivering').textContent = qty > 0 ? `${qty} ${unit}` : '—';
            el('diff').textContent       = qty > 0 ? (diff === 0 ? 'None' : `${diff > 0 ? '+' : '−'}${Math.abs(diff)} ${unit}`) : '—';
            el('diff').style.color       = state === 'ok' ? '#166534' : state === 'short' ? '#b45309' : '#b91c1c';

            const pct = order.ordered > 0 ? Math.min(100, Math.round((qty / order.ordered) * 100)) : 0;
            el('bar').style.width      = pct + '%';
            el('bar').style.background = { ok: '#22c55e', short: '#f59e0b', over: '#dc2626', invalid: '#dc2626' }[state];

            const name = escapeHtml(order.name);
            const text = {
                invalid: ['bi-x-octagon-fill',
                    `<b>Enter the quantity actually delivered.</b> It must be at least 1.`],
                over: ['bi-x-octagon-fill',
                    `<b>More than was ordered.</b> Only <b>${order.ordered} ${unit}</b> of ${name} were ordered and set aside from stock — ` +
                    `the extra <b>${diff} ${unit}</b> were never deducted. Record a separate order for them.`],
                short: ['bi-exclamation-triangle-fill',
                    `<b>Partial delivery.</b> <b>${-diff} ${unit}</b> of ${name} will not be delivered and ` +
                    `<b>will be returned to stock</b> (${order.stock} → ${order.stock - diff} ${unit}).`],
                ok: ['bi-check-circle-fill',
                    `Full delivery — matches the order exactly.`],
            }[state];

            el('icon').className = 'bi ' + text[0];
            el('text').innerHTML = text[1];

            if (current !== state) ack.checked = false;
            el('acktext').textContent =
                `I confirm only ${qty} of ${order.ordered} ${unit} were delivered, and ${-diff} ${unit} should go back to stock.`;

            // "below" styling doubles as the amber partial-delivery style.
            panel.className = 'sg-panel show state-' + ({ short: 'below', invalid: 'over' }[state] || state);
            panel.querySelector('.sg-ack').style.display = state === 'short' ? 'flex' : 'none';
            qtyEl.classList.toggle('sg-invalid', state === 'over' || (state === 'invalid' && qtyEl.value !== ''));

            block(state === 'over' || state === 'invalid' || (state === 'short' && !ack.checked));
            submit.innerHTML = state === 'over'
                ? '<i class="bi bi-slash-circle"></i> More than ordered'
                : state === 'short' && ack.checked
                    ? '<i class="bi bi-check-circle"></i> Confirm Partial Delivery'
                    : label;

            current = state;
        }

        qtyEl.addEventListener('input', update);
        ack.addEventListener('change', update);

        form.addEventListener('submit', e => {
            update();
            if (submit.disabled) {
                e.preventDefault();
                panel.classList.remove('sg-shake');
                void panel.offsetWidth;
                panel.classList.add('sg-shake');
                (current === 'short' ? ack : qtyEl).focus();
            }
        });

        return {
            load(o) {
                order = {
                    ordered: parseInt(o.ordered, 10) || 0,
                    unit:    o.unit || 'pcs',
                    name:    o.name || 'this product',
                    stock:   parseInt(o.stock, 10) || 0,
                };
                qtyEl.max = order.ordered;
                ack.checked = false;
                current = null;
                update();
            }
        };
    }

    window.StockGuard = { attach, attachDelivery, assess };
})();
