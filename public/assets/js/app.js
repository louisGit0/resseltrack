/* ResellTrack — vanilla JS : aperçus en direct, taux de change, graphique dashboard. */
(function () {
    'use strict';

    const num = (el) => {
        if (!el) return 0;
        const v = parseFloat(String(el.value).replace(',', '.'));
        return isNaN(v) ? 0 : v;
    };
    const intVal = (el) => {
        if (!el) return 0;
        const v = parseInt(el.value, 10);
        return isNaN(v) ? 0 : v;
    };
    const fmtEur = (n) => n.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
    const curSym = (code) => (code === 'USD' ? '$' : code === 'CNY' ? '¥' : '€');

    // ---------------------------------------------------------------
    // Formulaire achat : coût unitaire en direct + « Taux du jour »
    // ---------------------------------------------------------------
    function initPurchaseForm() {
        const form = document.getElementById('purchase-form');
        if (!form) return;

        const lot = document.getElementById('lot_price');
        const ship = document.getElementById('shipping_cost');
        const customs = document.getElementById('customs_cost');
        const qty = document.getElementById('quantity');
        const currency = document.getElementById('currency');
        const rate = document.getElementById('exchange_rate');
        const rateWrap = document.getElementById('rate-wrapper');
        const output = document.getElementById('unit-cost-output');
        const rateBtn = document.getElementById('fetch-rate');
        const rateStatus = document.getElementById('rate-status');
        const suffixes = form.querySelectorAll('.currency-suffix');

        const rateFrom = document.getElementById('rate-from');

        function syncCurrency() {
            const isForeign = currency.value !== 'EUR';
            rateWrap.classList.toggle('d-none', !isForeign);
            suffixes.forEach((s) => { s.textContent = curSym(currency.value); });
            if (rateFrom) rateFrom.textContent = isForeign ? currency.value : 'devise';
            if (!isForeign) {
                rate.value = '1';
            } else if (num(rate) === 1 || rate.value === '') {
                rate.value = '';
            }
            recompute();
        }

        function recompute() {
            const q = intVal(qty);
            const r = currency.value !== 'EUR' ? num(rate) : 1;
            if (q > 0 && r > 0) {
                const unit = ((num(lot) + num(ship) + num(customs)) * r) / q;
                output.textContent = fmtEur(unit) + ' / unité';
                output.classList.remove('text-muted');
            } else {
                output.textContent = '—';
                output.classList.add('text-muted');
            }
        }

        if (rateBtn) {
            rateBtn.addEventListener('click', async function () {
                const from = currency.value;
                if (from === 'EUR') return;
                rateStatus.textContent = 'Chargement…';
                try {
                    const res = await fetch('https://api.frankfurter.dev/v1/latest?from=' + from + '&to=EUR');
                    const data = await res.json();
                    const r = data && data.rates && data.rates.EUR;
                    if (r) {
                        rate.value = r;
                        rateStatus.textContent = 'Taux du ' + (data.date || 'jour') + ' : 1 ' + from + ' = ' + r + ' €';
                        recompute();
                    } else {
                        rateStatus.textContent = 'Taux indisponible, saisissez-le manuellement.';
                    }
                } catch (e) {
                    rateStatus.textContent = 'Erreur réseau, saisissez le taux manuellement.';
                }
            });
        }

        [lot, ship, customs, qty, rate].forEach((el) => {
            if (el) el.addEventListener('input', recompute);
        });
        currency.addEventListener('change', syncCurrency);

        syncCurrency();
    }

    // ---------------------------------------------------------------
    // Formulaire vente : marge prévisionnelle + garde-fou stock
    // ---------------------------------------------------------------
    function initSaleForm() {
        const form = document.getElementById('sale-form');
        if (!form) return;

        const product = document.getElementById('product_id');
        const qty = document.getElementById('quantity');
        const price = document.getElementById('sale_price');
        const pack = document.getElementById('packaging_cost');
        const label = document.getElementById('label_cost');
        const boost = document.getElementById('boost_cost');
        const other = document.getElementById('other_cost');
        const marginOut = document.getElementById('margin-output');
        const pctOut = document.getElementById('margin-pct-output');
        const stockOut = document.getElementById('stock-output');

        function selected() {
            return product && product.selectedIndex >= 0 ? product.options[product.selectedIndex] : null;
        }

        function recompute() {
            const opt = selected();
            const cump = opt ? parseFloat(opt.getAttribute('data-cump') || '0') : 0;
            const stock = opt ? parseInt(opt.getAttribute('data-stock') || '0', 10) : 0;
            const q = intVal(qty);

            if (stockOut) {
                if (opt && opt.value) {
                    const over = q > stock;
                    stockOut.innerHTML = (over ? '⚠️ ' : '') + 'Stock disponible : <strong>' + stock + '</strong> · CUMP : <strong>' + fmtEur(cump) + '</strong>';
                    stockOut.classList.toggle('text-danger', over);
                } else {
                    stockOut.textContent = '';
                }
            }

            const net = (num(price) - num(pack) - num(label) - num(boost) - num(other)) - (cump * q);
            if (q > 0 && opt && opt.value) {
                marginOut.textContent = (net >= 0 ? '+' : '') + fmtEur(net);
                marginOut.classList.toggle('profit-positive', net >= 0);
                marginOut.classList.toggle('profit-negative', net < 0);
                const pct = num(price) > 0 ? (net / num(price)) * 100 : 0;
                if (pctOut) pctOut.textContent = 'soit ' + pct.toLocaleString('fr-FR', { maximumFractionDigits: 1 }) + ' % du prix de vente';
            } else {
                marginOut.textContent = '—';
                marginOut.classList.remove('profit-positive', 'profit-negative');
                if (pctOut) pctOut.textContent = '';
            }
        }

        [product, qty, price, pack, label, boost, other].forEach((el) => {
            if (el) {
                el.addEventListener('input', recompute);
                el.addEventListener('change', recompute);
            }
        });
        recompute();
    }

    // ---------------------------------------------------------------
    // Graphique dashboard (données JSON dans #chart-data)
    // ---------------------------------------------------------------
    const MONTHS_FR = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

    function monthLabel(ym) {
        const parts = String(ym).split('-');
        if (parts.length !== 2) return ym;
        const m = parseInt(parts[1], 10);
        return (MONTHS_FR[m - 1] || ym) + ' ' + parts[0].slice(2);
    }

    function initDashboardChart() {
        const canvas = document.getElementById('monthly-chart');
        const dataEl = document.getElementById('chart-data');
        if (!canvas || !dataEl || typeof Chart === 'undefined') return;

        let payload;
        try {
            payload = JSON.parse(dataEl.textContent);
        } catch (e) {
            return;
        }

        new Chart(canvas, {
            data: {
                labels: payload.labels.map(monthLabel),
                datasets: [
                    {
                        type: 'bar',
                        label: "Chiffre d'affaires",
                        data: payload.revenue,
                        backgroundColor: 'rgba(14, 116, 144, .18)',
                        hoverBackgroundColor: 'rgba(14, 116, 144, .38)',
                        borderRadius: 7,
                        borderSkipped: false,
                        maxBarThickness: 34,
                        order: 2
                    },
                    {
                        type: 'line',
                        label: 'Bénéfice net',
                        data: payload.profit,
                        borderColor: '#0c9f6e',
                        backgroundColor: 'rgba(12, 159, 110, .12)',
                        pointBackgroundColor: '#0c9f6e',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 1.5,
                        pointRadius: 3.5,
                        pointHoverRadius: 5,
                        borderWidth: 2.5,
                        tension: .35,
                        fill: true,
                        order: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        position: 'top',
                        align: 'end',
                        labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 7, boxHeight: 7, font: { size: 11, family: 'Inter' }, color: '#64748b' }
                    },
                    tooltip: {
                        backgroundColor: '#0b1410',
                        padding: 12,
                        cornerRadius: 12,
                        titleFont: { family: 'Sora', weight: '700' },
                        bodyFont: { family: 'Inter' },
                        callbacks: {
                            label: function (ctx) {
                                return ' ' + ctx.dataset.label + ' : ' + fmtEur(ctx.parsed.y);
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 11, family: 'Inter' }, color: '#9aa89e' }
                    },
                    y: {
                        beginAtZero: true,
                        border: { display: false },
                        grid: { color: '#f0f2ec' },
                        ticks: {
                            font: { size: 11, family: 'Inter' },
                            color: '#9aa89e',
                            callback: function (v) { return v + ' €'; }
                        }
                    }
                }
            }
        });
    }

    // ---------------------------------------------------------------
    // Formulaire commande : lignes dynamiques + répartition du port
    // au prorata du poids, en direct
    // ---------------------------------------------------------------
    function initOrderForm() {
        const form = document.getElementById('order-form');
        if (!form) return;

        const currency = document.getElementById('o-currency');
        const rate = document.getElementById('o-rate');
        const rateWrap = document.getElementById('o-rate-wrapper');
        const rateBtn = document.getElementById('o-fetch-rate');
        const rateStatus = document.getElementById('o-rate-status');
        const shipping = document.getElementById('o-shipping');
        const customs = document.getElementById('o-customs');
        const linesBody = document.getElementById('order-lines');
        const template = document.getElementById('line-template');
        const addBtn = document.getElementById('add-line');

        const rateFrom = document.getElementById('o-rate-from');
        const sym = () => curSym(currency.value);
        const fmtCur = (n) => n.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + sym();

        function syncCurrency() {
            const isForeign = currency.value !== 'EUR';
            rateWrap.classList.toggle('d-none', !isForeign);
            form.querySelectorAll('.o-currency-suffix, .o-currency-symbol').forEach((s) => { s.textContent = sym(); });
            if (rateFrom) rateFrom.textContent = isForeign ? currency.value : 'devise';
            if (!isForeign) {
                rate.value = '1';
            } else if (num(rate) === 1 || rate.value === '') {
                rate.value = '';
            }
            recompute();
        }

        function recompute() {
            const rows = Array.from(linesBody.querySelectorAll('.order-line'));
            const r = currency.value !== 'EUR' ? num(rate) : 1;
            const ship = num(shipping);
            const cust = num(customs);

            // The price field is the UNIT price; line total = price × qty.
            // Same allocation basis as the server: line weight, then line
            // total price, then equal split.
            const data = rows.map((row) => {
                const qty = intVal(row.querySelector('.line-qty'));
                const price = num(row.querySelector('.line-price'));
                const weight = num(row.querySelector('.line-weight'));
                return { row, qty, price, lineTotal: price * qty, lineWeight: weight * qty };
            });

            let basis = data.map((d) => d.lineWeight);
            let total = basis.reduce((a, b) => a + b, 0);
            if (total <= 0) {
                basis = data.map((d) => d.lineTotal);
                total = basis.reduce((a, b) => a + b, 0);
            }
            if (total <= 0) {
                basis = data.map(() => 1);
                total = data.length;
            }

            let units = 0, priceSum = 0, weightSum = 0, eurSum = 0;
            data.forEach((d, i) => {
                const share = total > 0 ? basis[i] / total : 0;
                const allocShip = ship * share;
                const allocCust = cust * share;
                const allocEl = d.row.querySelector('.line-alloc');
                const unitEl = d.row.querySelector('.line-unit');

                if (d.qty > 0 && r > 0) {
                    const unit = ((d.lineTotal + allocShip + allocCust) * r) / d.qty;
                    allocEl.textContent = '+ ' + fmtCur(allocShip + allocCust) + ' de frais';
                    unitEl.textContent = fmtEur(unit) + ' / u';
                    units += d.qty;
                    priceSum += d.lineTotal;
                    weightSum += d.lineWeight;
                    eurSum += (d.lineTotal + allocShip + allocCust) * r;
                } else {
                    allocEl.textContent = '—';
                    unitEl.textContent = '—';
                }
            });

            const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
            set('o-total-units', units > 0 ? '× ' + units : '—');
            set('o-total-price', priceSum > 0 ? fmtCur(priceSum) : '—');
            set('o-total-weight', weightSum > 0 ? weightSum.toLocaleString('fr-FR') + ' g' : '—');
            set('o-total-eur', eurSum > 0 && r > 0 ? fmtEur(eurSum) : '—');
            set('o-grand-total', eurSum > 0 && r > 0 ? fmtEur(eurSum) : '—');
        }

        addBtn.addEventListener('click', function () {
            linesBody.appendChild(template.content.cloneNode(true));
            recompute();
        });

        linesBody.addEventListener('click', function (e) {
            const btn = e.target.closest('.remove-line');
            if (!btn) return;
            if (linesBody.querySelectorAll('.order-line').length > 1) {
                btn.closest('.order-line').remove();
            } else {
                // Last row: clear it instead of removing.
                btn.closest('.order-line').querySelectorAll('input').forEach((i) => { i.value = ''; });
            }
            recompute();
        });

        form.addEventListener('input', recompute);
        currency.addEventListener('change', syncCurrency);

        if (rateBtn) {
            rateBtn.addEventListener('click', async function () {
                const from = currency.value;
                if (from === 'EUR') return;
                rateStatus.textContent = 'Chargement…';
                try {
                    const res = await fetch('https://api.frankfurter.dev/v1/latest?from=' + from + '&to=EUR');
                    const data = await res.json();
                    const v = data && data.rates && data.rates.EUR;
                    if (v) {
                        rate.value = v;
                        rateStatus.textContent = 'Taux du ' + (data.date || 'jour') + ' : 1 ' + from + ' = ' + v + ' €';
                        recompute();
                    } else {
                        rateStatus.textContent = 'Taux indisponible';
                    }
                } catch (e) {
                    rateStatus.textContent = 'Erreur réseau, saisissez le taux manuellement.';
                }
            });
        }

        syncCurrency();
    }

    // ---------------------------------------------------------------
    // Donut bénéfice par catégorie (dashboard)
    // ---------------------------------------------------------------
    function initCategoryChart() {
        const canvas = document.getElementById('category-chart');
        const dataEl = document.getElementById('category-data');
        if (!canvas || !dataEl || typeof Chart === 'undefined') return;

        let payload;
        try {
            payload = JSON.parse(dataEl.textContent);
        } catch (e) {
            return;
        }
        if (!payload.labels || payload.labels.length === 0) {
            canvas.parentElement.innerHTML = '<div class="text-muted small d-flex align-items-center justify-content-center h-100">Aucun bénéfice positif sur la période.</div>';
            return;
        }

        const palette = ['#0c9f6e', '#f59e0b', '#0e7490', '#8b5cf6', '#f43f5e', '#14b8a6', '#84cc16', '#f97316'];

        new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: payload.labels,
                datasets: [{
                    data: payload.values,
                    backgroundColor: payload.labels.map((_, i) => palette[i % palette.length]),
                    borderWidth: 2,
                    borderColor: '#fff',
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 7, boxHeight: 7, font: { size: 11, family: 'Inter' }, color: '#64748b' }
                    },
                    tooltip: {
                        backgroundColor: '#0b1410',
                        padding: 10,
                        cornerRadius: 12,
                        bodyFont: { family: 'Inter' },
                        callbacks: {
                            label: function (ctx) {
                                const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                const share = total > 0 ? Math.round((ctx.parsed / total) * 100) : 0;
                                return ' ' + ctx.label + ' : ' + fmtEur(ctx.parsed) + ' (' + share + ' %)';
                            }
                        }
                    }
                }
            }
        });
    }

    // ---------------------------------------------------------------
    // Visionneuse photos (galerie produit)
    // ---------------------------------------------------------------
    function initPhotoGallery() {
        const modalEl = document.getElementById('photo-modal');
        if (!modalEl || typeof bootstrap === 'undefined') return;

        const imgEl = document.getElementById('photo-modal-img');
        const modal = new bootstrap.Modal(modalEl);

        document.addEventListener('click', function (e) {
            const thumb = e.target.closest('.gallery-thumb');
            if (!thumb) return;
            imgEl.src = thumb.getAttribute('data-full') || thumb.src;
            modal.show();
        });
    }

    // ---------------------------------------------------------------
    // Toasts : fermeture automatique des messages flash
    // ---------------------------------------------------------------
    function initToasts() {
        if (typeof bootstrap === 'undefined') return;
        document.querySelectorAll('.toast-stack .alert[data-autodismiss]').forEach(function (el, i) {
            setTimeout(function () {
                try { bootstrap.Alert.getOrCreateInstance(el).close(); } catch (e) { el.remove(); }
            }, 4500 + i * 600);
        });
    }

    // ---------------------------------------------------------------
    // Compteurs animés sur les chiffres clés (KPI, bandeaux de stats)
    // ---------------------------------------------------------------
    function initCountUp() {
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        document.querySelectorAll('.kpi-value, .stat-num').forEach(function (el) {
            const original = el.textContent.trim();
            // « +1 234,56 € », « 42 j », « 12 »… — on anime la partie numérique.
            const m = original.match(/^([+\-]?)([\d\s  ]+(?:,\d+)?)(.*)$/);
            if (!m) return;
            const sign = m[1];
            const target = parseFloat(m[2].replace(/[\s  ]/g, '').replace(',', '.'));
            if (isNaN(target)) return;
            const decimals = (m[2].split(',')[1] || '').length;
            const suffix = m[3];

            const duration = 600;
            const start = performance.now();
            function frame(now) {
                const p = Math.min(1, (now - start) / duration);
                const eased = 1 - Math.pow(1 - p, 3);
                el.textContent = sign + (target * eased).toLocaleString('fr-FR', {
                    minimumFractionDigits: decimals, maximumFractionDigits: decimals
                }) + suffix;
                if (p < 1) {
                    requestAnimationFrame(frame);
                } else {
                    el.textContent = original;
                }
            }
            requestAnimationFrame(frame);
        });
    }

    // ---------------------------------------------------------------
    // Modale de confirmation (formulaires avec data-confirm)
    // ---------------------------------------------------------------
    function initConfirmModal() {
        const modalEl = document.getElementById('confirm-modal');
        if (!modalEl || typeof bootstrap === 'undefined') return;

        const messageEl = document.getElementById('confirm-modal-message');
        const okBtn = document.getElementById('confirm-modal-ok');
        const modal = new bootstrap.Modal(modalEl);
        let pendingForm = null;

        document.addEventListener('submit', function (e) {
            const form = e.target.closest('form[data-confirm]');
            if (!form || form.dataset.confirmed === '1') return;
            e.preventDefault();
            pendingForm = form;
            messageEl.textContent = form.getAttribute('data-confirm') || 'Confirmer cette action ?';
            modal.show();
        });

        okBtn.addEventListener('click', function () {
            if (pendingForm) {
                pendingForm.dataset.confirmed = '1';
                modal.hide();
                pendingForm.submit();
                pendingForm = null;
            }
        });

        modalEl.addEventListener('hidden.bs.modal', function () {
            pendingForm = null;
        });
    }

    // ---------------------------------------------------------------
    // Widget de note par étoiles (réutilisable) — hidden input + bi-star
    // Cible chaque conteneur [data-star-rating] : pioche son input caché,
    // ses boutons .star-btn (data-value 1..5) et son bouton [data-star-clear].
    // Cliquable, effaçable (note nulle), avec aperçu au survol.
    // Conçu générique pour être réutilisé (ex. note produit, Phase 9).
    // ---------------------------------------------------------------
    function initStarRating() {
        const widgets = document.querySelectorAll('[data-star-rating]');
        if (!widgets.length) return; // garde : no-op si aucun widget sur la page

        widgets.forEach(function (widget) {
            const input = widget.querySelector('input[type="hidden"]');
            const stars = Array.from(widget.querySelectorAll('.star-btn'));
            const clearBtn = widget.querySelector('[data-star-clear]');
            if (!input || !stars.length) return;

            function paint(value) {
                stars.forEach(function (btn) {
                    const v = parseInt(btn.getAttribute('data-value'), 10) || 0;
                    const icon = btn.querySelector('i');
                    const filled = v <= value;
                    btn.classList.toggle('is-active', filled);
                    if (icon) {
                        icon.classList.toggle('bi-star-fill', filled);
                        icon.classList.toggle('bi-star', !filled);
                    }
                });
            }

            function current() {
                const v = parseInt(input.value, 10);
                return isNaN(v) ? 0 : v;
            }

            stars.forEach(function (btn) {
                const v = parseInt(btn.getAttribute('data-value'), 10) || 0;
                btn.addEventListener('click', function () {
                    input.value = String(v);
                    paint(v);
                });
                btn.addEventListener('mouseenter', function () { paint(v); }); // aperçu survol
            });

            widget.addEventListener('mouseleave', function () { paint(current()); });

            if (clearBtn) {
                clearBtn.addEventListener('click', function () {
                    input.value = ''; // note nulle → le serveur stocke null
                    paint(0);
                });
            }

            paint(current()); // état initial (mode édition pré-rempli)
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initPurchaseForm();
        initSaleForm();
        initOrderForm();
        initDashboardChart();
        initCategoryChart();
        initConfirmModal();
        initStarRating();
        initToasts();
        initCountUp();
        initPhotoGallery();
    });
})();
