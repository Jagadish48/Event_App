// Event Management System JavaScript

// Global variables
let currentLocation = null;
const EMS_THEME_KEY = 'ems_theme';

function getStoredTheme() {
    try {
        const t = localStorage.getItem(EMS_THEME_KEY);
        return t === 'light' || t === 'dark' ? t : '';
    } catch (e) {
        return '';
    }
}

function applyTheme(theme, persist = true) {
    const t = theme === 'light' ? 'light' : 'dark';
    document.documentElement.dataset.theme = t;
    try {
        document.documentElement.style.colorScheme = t;
    } catch (e) {}

    if (persist) {
        try { localStorage.setItem(EMS_THEME_KEY, t); } catch (e) {}
    }

    const toggle = document.getElementById('appThemeToggle');
    if (toggle) {
        toggle.checked = t === 'light';
    }

    applyThemeToCharts();
    try {
        document.dispatchEvent(new CustomEvent('ems:theme', { detail: { theme: t } }));
    } catch (e) {}
}

function initializeTheme() {
    const stored = getStoredTheme();
    applyTheme(stored || 'dark', false);

    const toggle = document.getElementById('appThemeToggle');
    if (!toggle) return;

    toggle.addEventListener('change', function() {
        applyTheme(toggle.checked ? 'light' : 'dark', true);
    });

    try {
        document.addEventListener('ems:theme', function(e) {
            const t = (e && e.detail && e.detail.theme) ? e.detail.theme : (document.documentElement.dataset.theme || 'dark');
            applyBranding(t);
        });
    } catch (e) {}
}

function applyThemeToCharts() {
    if (typeof Chart === 'undefined') return;

    const styles = getComputedStyle(document.documentElement);
    const text = (styles.getPropertyValue('--text') || '').trim() || '#111827';
    const border = (styles.getPropertyValue('--border') || '').trim() || 'rgba(0,0,0,0.12)';
    const heading = (styles.getPropertyValue('--heading') || '').trim() || text;

    try {
        Chart.defaults.color = text;
        if (Chart.defaults.plugins && Chart.defaults.plugins.legend && Chart.defaults.plugins.legend.labels) {
            Chart.defaults.plugins.legend.labels.color = heading;
        }
        if (Chart.defaults.scale) {
            Chart.defaults.scale.grid = Chart.defaults.scale.grid || {};
            Chart.defaults.scale.grid.color = border;
            Chart.defaults.scale.ticks = Chart.defaults.scale.ticks || {};
            Chart.defaults.scale.ticks.color = text;
        }
    } catch (e) {}

    const charts = [];
    try {
        if (Chart.instances) {
            Object.keys(Chart.instances).forEach(function(k) {
                if (Chart.instances[k]) charts.push(Chart.instances[k]);
            });
        } else if (typeof Chart.getChart === 'function') {
            document.querySelectorAll('canvas').forEach(function(c) {
                const ch = Chart.getChart(c);
                if (ch) charts.push(ch);
            });
        }
    } catch (e) {}

    charts.forEach(function(ch) {
        try {
            if (ch.options && ch.options.plugins && ch.options.plugins.legend && ch.options.plugins.legend.labels) {
                ch.options.plugins.legend.labels.color = heading;
            }
            if (ch.options && ch.options.scales) {
                Object.keys(ch.options.scales).forEach(function(axis) {
                    const sc = ch.options.scales[axis];
                    if (sc && sc.ticks) sc.ticks.color = text;
                    if (sc && sc.grid) sc.grid.color = border;
                });
            }
            ch.update('none');
        } catch (e) {}
    });
}

applyTheme(getStoredTheme() || (document.documentElement.dataset.theme || 'dark'), false);

function initializeProfilePhotoPreview() {
    const inputs = document.querySelectorAll('input[type="file"][name="profile_photo"]');
    if (!inputs || inputs.length < 1) return;

    inputs.forEach(function(input) {
        let lastUrl = '';
        const root = input.closest('.profile-photo-row') || input.closest('.card') || document;
        const preview = root.querySelector('#profile_photo_preview') || root.querySelector('[data-profile-photo-preview]');

        function setPreview(url) {
            if (!preview) return;
            preview.innerHTML = '';
            if (!url) return;
            const img = document.createElement('img');
            img.src = url;
            img.alt = 'Preview';
            img.style.width = '96px';
            img.style.height = '96px';
            img.style.borderRadius = '999px';
            img.style.objectFit = 'cover';
            img.style.border = '1px solid var(--border)';
            img.style.background = 'var(--surface)';
            preview.appendChild(img);
        }

        input.addEventListener('change', function() {
            try {
                const f = input.files && input.files[0] ? input.files[0] : null;
                if (!f) {
                    if (lastUrl) URL.revokeObjectURL(lastUrl);
                    lastUrl = '';
                    setPreview('');
                    return;
                }

                const maxBytes = 2 * 1024 * 1024;
                const typeOk = ['image/jpeg', 'image/png', 'image/webp'].includes((f.type || '').toLowerCase());
                const ext = (f.name || '').split('.').pop().toLowerCase();
                const extOk = ['jpg', 'jpeg', 'png', 'webp'].includes(ext);

                if (f.size > maxBytes) {
                    input.value = '';
                    setPreview('');
                    if (typeof showAlert === 'function') showAlert('danger', 'Max file size is 2MB.');
                    return;
                }
                if (!typeOk && !extOk) {
                    input.value = '';
                    setPreview('');
                    if (typeof showAlert === 'function') showAlert('danger', 'Only JPG, JPEG, PNG, and WEBP files are allowed.');
                    return;
                }

                if (lastUrl) URL.revokeObjectURL(lastUrl);
                lastUrl = URL.createObjectURL(f);
                setPreview(lastUrl);
            } catch (error) {
                console.error('Profile Photo Preview Error:', error);
            }
        });
    });
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    initializeTheme();
    initializeApp();
    initializeMotion();
    initializeCounters();
    initializeTaskWorkflow();
    initializeSidebarActiveLink();
    initializeUrlAlerts();
    initializeAutoSubmitFilters();
    initializeSearchableSelects();
    initializeSmartTables();
    initializeResponsiveTables();
    initializeProfilePhotoPreview();

    const sidebar = document.querySelector('.sidebar');
    if (sidebar) {
        sidebar.querySelectorAll('a.nav-link').forEach(function(link) {
            link.addEventListener('click', function() {
                if (window.matchMedia('(max-width: 992px)').matches) {
                    toggleSidebar(true);
                }
            });
        });
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            toggleSidebar(true);
        }
    });

    // Set up MutationObserver to watch for new DOM elements and reinitialize buttons
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.addedNodes.length > 0) {
                initializeActionButtonsTargets();
                initializeSidebarTooltipsTargets();
                initializeResponsiveTables();
            }
        });
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true
    });

    // Sidebar Quick Action Modals Fix
    document.querySelectorAll('.sidebar-quick a, a[href*="events.php?open=add"], a[href*="employees.php?open=add"], a[href*="expenses.php?open=add"], a[href*="project_expense_report.php?open=upload"]').forEach(function(link) {
        link.addEventListener('click', async function(e) {
            const href = link.getAttribute('href') || '';
            
            let modalId = '';
            let fetchUrl = '';
            
            if (href.includes('events.php?open=add')) {
                modalId = 'addEventModal';
                fetchUrl = 'events.php';
            } else if (href.includes('employees.php?open=add')) {
                modalId = 'addEmployeeModal';
                fetchUrl = 'employees.php';
            } else if (href.includes('expenses.php?open=add')) {
                modalId = 'addExpenseModal';
                fetchUrl = 'expenses.php';
            } else if (href.includes('project_expense_report.php?open=upload')) {
                modalId = 'uploadReportModal';
                fetchUrl = 'project_expense_report.php';
            } else {
                return;
            }
            
            e.preventDefault();
            e.stopPropagation();
            
            let modalEl = document.getElementById(modalId);
            
            if (!modalEl) {
                try {
                    const response = await fetch(fetchUrl);
                    const text = await response.text();
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(text, 'text/html');
                    const fetchedModal = doc.getElementById(modalId);
                    if (fetchedModal) {
                        document.body.appendChild(fetchedModal);
                        modalEl = document.getElementById(modalId);
                    }
                } catch (err) {
                    console.error('Failed to fetch modal', err);
                }
            }
            
            if (modalEl) {
                const form = modalEl.querySelector('form');
                if (form && form.dataset.quickActionAttached !== '1') {
                    form.dataset.quickActionAttached = '1';
                    form.addEventListener('submit', async function(ev) {
                        if (!form.checkValidity()) return;
                        ev.preventDefault();
                        
                        const submitBtn = form.querySelector('button[type="submit"]');
                        const origHtml = submitBtn ? submitBtn.innerHTML : '';
                        if (submitBtn) {
                            submitBtn.disabled = true;
                            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving...';
                        }
                        
                        try {
                            const formData = new FormData(form);
                            // Ensure the request goes to the correct handler
                            const res = await fetch(fetchUrl, {
                                method: 'POST',
                                body: formData
                            });
                            
                            if (res.ok) {
                                bootstrap.Modal.getInstance(modalEl).hide();
                                form.reset();
                                if (typeof showToast === 'function') {
                                    showToast('success', 'Successfully saved!');
                                }
                            } else {
                                if (typeof showToast === 'function') {
                                    showToast('danger', 'Failed to save. Please try again.');
                                }
                            }
                        } catch (err) {
                            console.error(err);
                        } finally {
                            if (submitBtn) {
                                submitBtn.disabled = false;
                                submitBtn.innerHTML = origHtml;
                            }
                            form.dataset.emsSubmitting = '0';
                        }
                    });
                }
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            } else {
                window.location.href = href;
            }
        });
    });

    // Global listener to clear modal-related URL parameters when any modal closes
    document.addEventListener('hidden.bs.modal', function(e) {
        try {
            const url = new URL(window.location);
            if (url.searchParams.has('open') || url.searchParams.has('focus') || url.searchParams.has('modal')) {
                url.searchParams.delete('open');
                url.searchParams.delete('focus');
                url.searchParams.delete('modal');
                window.history.replaceState({}, '', url);
            }
        } catch (err) {}
    });
});

function initializeResponsiveTables() {
    document.querySelectorAll('table.table').forEach(function(table) {
        if (!table.parentElement.classList.contains('table-responsive') && !table.closest('.table-responsive')) {
            const wrapper = document.createElement('div');
            wrapper.className = 'table-responsive w-100 mb-0';
            table.parentNode.insertBefore(wrapper, table);
            wrapper.appendChild(table);
        }
    });
}

function initializeSidebarActiveLink() {
    const sidebar = document.querySelector('.sidebar');
    if (!sidebar) return;

    const existingActive = sidebar.querySelector('.nav-link.active');
    if (existingActive) return;

    const currentFile = (window.location.pathname || '').split('/').pop();
    if (!currentFile) return;

    const links = Array.from(sidebar.querySelectorAll('a.nav-link[href]'));
    const best = links.find(function(a) {
        const href = a.getAttribute('href') || '';
        const file = href.split('?')[0].split('#')[0].split('/').pop();
        return file && file.toLowerCase() === currentFile.toLowerCase();
    });

    if (best) {
        best.classList.add('active');
    }
}

function initializeUrlAlerts() {
    try {
        const url = new URL(window.location.href);
        const params = url.searchParams;

        const success = params.get('success');
        const error = params.get('error');
        const warning = params.get('warning');
        const info = params.get('info');

        const shown = [];
        if (success) shown.push(['success', success]);
        if (error) shown.push(['danger', error]);
        if (warning) shown.push(['warning', warning]);
        if (info) shown.push(['info', info]);

        if (!shown.length) return;

        const last = shown[shown.length - 1];
        showAlert(last[0], last[1]);

        ['success', 'error', 'warning', 'info'].forEach(function(k) { params.delete(k); });
        window.history.replaceState({}, document.title, url.pathname + (params.toString() ? '?' + params.toString() : '') + url.hash);
    } catch (e) {
        return;
    }
}

function initializeSidebarTooltipsTargets() {
    const items = document.querySelectorAll('.sidebar a.nav-link, .sidebar .sidebar-quick a.btn');
    items.forEach(function(el) {
        if (el.dataset.emsTooltipInit === '1') return;
        el.dataset.emsTooltipInit = '1';

        const label = (el.textContent || '').replace(/\s+/g, ' ').trim();
        if (!label) return;

        if (!el.getAttribute('title')) {
            el.setAttribute('title', label);
        }
        if (!el.getAttribute('data-bs-toggle')) {
            el.setAttribute('data-bs-toggle', 'tooltip');
        }
        if (!el.getAttribute('data-bs-placement')) {
            el.setAttribute('data-bs-placement', 'right');
        }
    });
}

function inferActionMetaFromElement(el) {
    const icon = el.querySelector('i');
    const iconClass = icon ? (icon.className || '') : '';
    const rawText = (el.getAttribute('aria-label') || el.getAttribute('title') || el.textContent || '').replace(/\s+/g, ' ').trim();
    const text = rawText.toLowerCase();

    const rules = [
        { key: 'delete', label: 'Delete', icon: 'fa-trash', btn: 'btn-danger', iconNeedles: ['fa-trash', 'fa-trash-alt'] },
        { key: 'view', label: 'View', icon: 'fa-eye', btn: 'btn-info', iconNeedles: ['fa-eye'] },
        { key: 'edit', label: 'Edit', icon: 'fa-edit', btn: 'btn-info', iconNeedles: ['fa-edit', 'fa-pen', 'fa-pen-to-square', 'fa-pencil-alt'] },
        { key: 'add', label: 'Add', icon: 'fa-plus', btn: 'btn-primary', iconNeedles: ['fa-plus', 'fa-plus-circle', 'fa-user-plus'] },
        { key: 'complete', label: 'Complete', icon: 'fa-check-circle', btn: 'btn-success', iconNeedles: ['fa-check', 'fa-check-circle', 'fa-circle-check'] },
        { key: 'approve', label: 'Approve', icon: 'fa-check-circle', btn: 'btn-success', iconNeedles: ['fa-thumbs-up', 'fa-badge-check'] }
    ];

    const byIcon = rules.find(function(r) {
        return r.iconNeedles.some(function(n) { return iconClass.indexOf(n) !== -1; });
    });
    if (byIcon) return byIcon;

    if (/(^|[^a-z])delete([^a-z]|$)|(^|[^a-z])remove([^a-z]|$)/.test(text)) return rules[0];
    if (/(^|[^a-z])view([^a-z]|$)|(^|[^a-z])details([^a-z]|$)|(^|[^a-z])open([^a-z]|$)/.test(text)) return rules[1];
    if (/(^|[^a-z])edit([^a-z]|$)|(^|[^a-z])update([^a-z]|$)/.test(text)) return rules[2];
    if (/(^|[^a-z])add([^a-z]|$)|(^|[^a-z])create([^a-z]|$)|(^|[^a-z])new([^a-z]|$)/.test(text)) return rules[3];
    if (/(^|[^a-z])complete([^a-z]|$)|(^|[^a-z])approved([^a-z]|$)|(^|[^a-z])approve([^a-z]|$)|(^|[^a-z])submit([^a-z]|$)/.test(text)) return rules[4];

    return null;
}

function initializeActionButtonsTargets() {
    const candidates = document.querySelectorAll(
        'table a.btn, table button.btn, .table-responsive a.btn, .table-responsive button.btn'
    );

    candidates.forEach(function(el) {
        if (el.dataset.emsActionInit === '1') return;
        el.dataset.emsActionInit = '1';

        const meta = inferActionMetaFromElement(el);
        if (!meta) return;

        el.classList.add('btn-action');

        const hasContextual =
            el.classList.contains('btn-primary') ||
            el.classList.contains('btn-info') ||
            el.classList.contains('btn-success') ||
            el.classList.contains('btn-warning') ||
            el.classList.contains('btn-danger') ||
            el.classList.contains('btn-secondary') ||
            Array.from(el.classList).some(function(c) { return c.indexOf('btn-outline-') === 0; });

        const hasOutline = Array.from(el.classList).some(function(c) { return c.indexOf('btn-outline-') === 0; });
        const isLink = el.classList.contains('btn-link');

        if (!hasOutline && !isLink) {
            ['btn-primary', 'btn-info', 'btn-success', 'btn-warning', 'btn-danger', 'btn-secondary'].forEach(function(v) {
                el.classList.remove(v);
            });
            el.classList.add(meta.btn);
        } else if (!hasContextual) {
            el.classList.add(meta.btn);
        }

        const hasIcon = !!el.querySelector('i');
        const hasText = ((el.textContent || '').replace(/\s+/g, ' ').trim()).length > 0;
        if (!hasIcon && (hasText || meta.key) && el.dataset.emsIconized !== '1') {
            const i = document.createElement('i');
            i.className = 'fas ' + meta.icon + (hasText ? ' me-2' : '');
            el.insertBefore(i, el.firstChild);
            el.dataset.emsIconized = '1';
        }

        const label = meta.label;
        if (!el.getAttribute('aria-label')) {
            el.setAttribute('aria-label', label);
        }
        if (!el.getAttribute('title')) {
            el.setAttribute('title', label);
        }
        if (!el.getAttribute('data-bs-toggle')) {
            el.setAttribute('data-bs-toggle', 'tooltip');
        }
        if (!el.getAttribute('data-bs-placement')) {
            el.setAttribute('data-bs-placement', 'top');
        }
    });
}

function getBrandingData() {
    const b = document.body;
    if (!b) return { appName: '', panelLabel: '', main: '', dark: '', light: '', login: '', favicon: '' };
    return {
        appName: (b.getAttribute('data-app-name') || '').trim(),
        panelLabel: (b.getAttribute('data-panel-label') || '').trim(),
        main: b.getAttribute('data-logo-main') || '',
        dark: b.getAttribute('data-logo-dark') || '',
        light: b.getAttribute('data-logo-light') || '',
        login: b.getAttribute('data-logo-login') || '',
        favicon: b.getAttribute('data-favicon') || ''
    };
}

function pickLogoUrlForTheme(theme) {
    const d = getBrandingData();
    const isAuth = document.body && document.body.classList && document.body.classList.contains('auth-page');
    if (isAuth && d.login) return d.login;
    if (theme === 'light') return d.light || d.main || d.dark || '';
    return d.dark || d.main || d.light || '';
}

function applyBranding(theme) {
    const t = theme === 'light' ? 'light' : 'dark';
    const url = pickLogoUrlForTheme(t);
    const appName = (getBrandingData().appName || '').trim();

    document.querySelectorAll('img.js-brand-logo').forEach(function(img) {
        if (!url) return;
        if (img.getAttribute('src') !== url) img.setAttribute('src', url);
        if (appName) img.setAttribute('alt', appName + ' logo');
    });

    const faviconUrl = (getBrandingData().favicon || '').trim();
    if (faviconUrl) {
        const existing = document.querySelector('link[rel="icon"]');
        if (existing && existing.getAttribute('href') !== faviconUrl) {
            existing.setAttribute('href', faviconUrl);
        }
    }
}

function initializeSidebarBrand() {
    const sidebar = document.querySelector('.sidebar');
    if (!sidebar) return;

    const container = sidebar.querySelector('.p-3');
    if (!container) return;
    if (container.querySelector('.sidebar-brand')) return;

    const theme = document.documentElement.dataset.theme || 'dark';
    const url = pickLogoUrlForTheme(theme);
    const meta = getBrandingData();
    const appName = (meta.appName || '').trim() || 'NETWORK EVENTS';
    const panelLabel = (meta.panelLabel || '').trim() || 'Workspace';

    const wrap = document.createElement('div');
    wrap.className = 'sidebar-brand';

    if (url) {
        const img = document.createElement('img');
        img.className = 'app-logo-img js-brand-logo';
        img.alt = appName + ' logo';
        img.src = url;
        wrap.appendChild(img);
    } else {
        const mark = document.createElement('span');
        mark.className = 'app-logo-mark';
        mark.setAttribute('aria-hidden', 'true');
        mark.innerHTML = '<i class="fas fa-network-wired"></i>';
        wrap.appendChild(mark);
    }

    const text = document.createElement('div');
    text.className = 'sidebar-brand-text';
    text.innerHTML = '<div class="sidebar-brand-title">' + appName + '</div><div class="sidebar-brand-sub">' + panelLabel + '</div>';
    wrap.appendChild(text);

    container.insertBefore(wrap, container.firstChild);
}

function initializeApp() {
    initializeSidebarTooltipsTargets();
    initializeActionButtonsTargets();
    initializeSidebarBrand();
    applyBranding(document.documentElement.dataset.theme || 'dark');

    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Initialize modals
    initializeModals();
    
    // Initialize file uploads
    initializeFileUploads();
    
    // Initialize forms
    initializeForms();
    
    // Initialize charts if any
    if (typeof Chart !== 'undefined') {
        initializeCharts();
    }
}

function initializeMotion() {
    if (document.body) {
        requestAnimationFrame(function() {
            document.body.classList.add('is-loaded');
        });
    }

    animateStaticAlerts();
    initializeScrollReveal();
}

function animateStaticAlerts() {
    document.querySelectorAll('.main-content .alert').forEach(function(alert) {
        alert.classList.add('animate-in');
        if (alert.classList.contains('alert-danger')) {
            alert.classList.add('shake');
        }
    });
}

function initializeScrollReveal() {
    if (!('IntersectionObserver' in window)) return;

    const candidates = document.querySelectorAll(
        '.main-content .card, .main-content .stat-card, .main-content .attendance-card, .main-content .table-responsive, .main-content .chart-container'
    );

    if (!candidates.length) return;

    const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduce) {
        candidates.forEach(function(el) {
            el.classList.add('reveal');
            el.classList.add('is-visible');
        });
        return;
    }

    const observer = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
            if (!entry.isIntersecting) return;
            entry.target.classList.add('is-visible');
            observer.unobserve(entry.target);
        });
    }, { root: null, threshold: 0.12, rootMargin: '0px 0px -10% 0px' });

    candidates.forEach(function(el, idx) {
        el.classList.add('reveal');
        el.style.setProperty('--reveal-delay', (Math.min(idx, 12) * 60) + 'ms');
        observer.observe(el);
    });
}

function initializeModals() {
    // Auto-focus first input in modals
    document.querySelectorAll('.modal').forEach(function(modal) {
        if (modal.dataset.emsModalInit === '1') return;
        modal.dataset.emsModalInit = '1';

        modal.addEventListener('show.bs.modal', function() {
            if (modal.parentElement && modal.parentElement !== document.body) {
                document.body.appendChild(modal);
            }
        });

        modal.addEventListener('shown.bs.modal', function() {
            const backdrops = document.querySelectorAll('.modal-backdrop');
            const latestBackdrop = backdrops.length ? backdrops[backdrops.length - 1] : null;
            if (latestBackdrop) {
                latestBackdrop.classList.add('modal-overlay');
            }

            const firstInput = modal.querySelector('input:not([type="file"])');
            if (firstInput) {
                firstInput.focus();
            }
        });

        modal.addEventListener('hidden.bs.modal', function() {
            window.setTimeout(function() {
                if (document.querySelector('.modal.show')) return;

                document.querySelectorAll('.modal-backdrop').forEach(function(bd) { bd.remove(); });
                document.body.classList.remove('modal-open');
                document.body.style.removeProperty('overflow');
                document.body.style.removeProperty('padding-right');
            }, 0);
        });
    });

    if (document.body && document.body.dataset.emsModalGlobalCleanup !== '1') {
        document.body.dataset.emsModalGlobalCleanup = '1';
        document.addEventListener('hidden.bs.modal', function() {
            window.setTimeout(function() {
                if (document.querySelector('.modal.show')) return;
                document.querySelectorAll('.modal-backdrop').forEach(function(bd) { bd.remove(); });
                document.body.classList.remove('modal-open');
                document.body.style.removeProperty('overflow');
                document.body.style.removeProperty('padding-right');
            }, 0);
        });
    }
}

function initializeFileUploads() {
    // File upload preview
    document.querySelectorAll('input[type="file"]').forEach(function(input) {
        input.addEventListener('change', function(e) {
            const file = e.target.files[0];
            const preview = document.getElementById(this.id + '_preview');
            
            if (file && preview) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    if (file.type.startsWith('image/')) {
                        preview.innerHTML = `<img src="${e.target.result}" class="img-thumbnail" style="max-height: 200px;">`;
                    } else {
                        preview.innerHTML = `<div class="alert alert-info"><i class="fas fa-file me-2"></i>${file.name}</div>`;
                    }
                };
                reader.readAsDataURL(file);
            }
        });
    });
}

function showToast(type, message) {
    let container = document.getElementById('toastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toastContainer';
        container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
        container.style.zIndex = '9999';
        document.body.appendChild(container);
    }
    
    const bgClass = type === 'danger' ? 'bg-danger text-white' : 
                   (type === 'success' ? 'bg-success text-white' : 
                   (type === 'warning' ? 'bg-warning text-dark' : 'bg-primary text-white'));
                   
    const icon = type === 'danger' ? 'fa-exclamation-triangle' : 
                 (type === 'success' ? 'fa-check-circle' : 'fa-info-circle');

    const toastId = 'toast_' + Date.now() + Math.floor(Math.random() * 1000);
    
    const toastHtml = `
        <div id="${toastId}" class="toast align-items-center ${bgClass} border-0 mb-2 shadow-lg" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body d-flex align-items-center">
                    <i class="fas ${icon} me-3 fs-4"></i>
                    <div>${message}</div>
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        </div>
    `;
    
    container.insertAdjacentHTML('beforeend', toastHtml);
    const toastElement = document.getElementById(toastId);
    
    if (typeof bootstrap !== 'undefined' && bootstrap.Toast) {
        const toast = new bootstrap.Toast(toastElement, { autohide: true, delay: 4000 });
        toast.show();
        toastElement.addEventListener('hidden.bs.toast', function () {
            toastElement.remove();
        });
    } else {
        toastElement.classList.add('show');
        setTimeout(() => toastElement.remove(), 4000);
    }
}

function initializeForms() {
    // Form validation
    document.querySelectorAll('form').forEach(function(form) {
        form.addEventListener('invalid', function(e) {
            e.preventDefault(); // Prevents the native browser tooltip
            
            const field = e.target;
            const labelEl = form.querySelector(`label[for="${field.id}"]`);
            const label = labelEl ? labelEl.textContent : (field.placeholder || field.name || 'this field');
            const cleanLabel = label.replace(/[\*:]/g, '').trim();
            
            showToast('danger', `Please provide a valid entry for <strong>${cleanLabel}</strong>.`);
            
            if (!form.dataset.invalidFocused) {
                form.dataset.invalidFocused = '1';
                field.focus();
                setTimeout(function() { delete form.dataset.invalidFocused; }, 100);
            }
        }, true); // capture phase

        form.addEventListener('submit', function(e) {
            if (form.dataset.emsSubmitting === '1') {
                e.preventDefault();
                e.stopPropagation();
                return;
            }
            if (!form.checkValidity()) {
                e.preventDefault();
                e.stopPropagation();
                form.classList.add('was-validated');
                return;
            }

            form.dataset.emsSubmitting = '1';
            form.classList.add('was-validated');

            form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function(btn) {
                try {
                    btn.disabled = true;
                } catch (err) {}
            });
        });
    });
}

function initializeCharts() {
    // Chart initialization will be handled by individual pages
}

function initializeCounters() {
    const counters = document.querySelectorAll(
        '.stat-card .stat-value, .attendance-card .stat-value, [data-counter]'
    );

    if (!counters.length) return;
    const prefersReduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (prefersReduced) return;

    const parseNumber = function(text) {
        const cleaned = (text || '').replace(/[^0-9.]/g, '');
        if (!cleaned) return null;
        const value = Number(cleaned);
        return Number.isFinite(value) ? value : null;
    };

    const formatLike = function(originalText, value) {
        const hasDecimal = (originalText || '').includes('.');
        const decimals = hasDecimal ? Math.min(2, (originalText.split('.')[1] || '').length) : 0;
        let fixed = decimals > 0 ? value.toFixed(decimals) : Math.round(value).toString();
        if ((originalText || '').includes(',')) {
            fixed = Number(value).toLocaleString('en-IN', {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals
            });
        }
        return (originalText || '').replace(/([0-9][0-9,]*)(\.[0-9]+)?/, fixed);
    };

    const animate = function(el) {
        if (el.dataset.counterDone === '1') return;

        const raw = el.textContent.trim();
        const target = el.dataset.counter ? Number(el.dataset.counter) : parseNumber(raw);
        if (target === null || !Number.isFinite(target)) return;

        const duration = 900;
        const startTime = performance.now();
        const startValue = 0;
        const delta = target - startValue;

        el.dataset.counterDone = '1';

        const tick = function(now) {
            const t = Math.min(1, (now - startTime) / duration);
            const eased = 1 - Math.pow(1 - t, 3);
            const current = startValue + delta * eased;
            el.textContent = formatLike(raw, current);
            if (t < 1) {
                requestAnimationFrame(tick);
            } else {
                el.textContent = raw;
            }
        };

        requestAnimationFrame(tick);
    };

    if (!('IntersectionObserver' in window)) {
        counters.forEach(animate);
        return;
    }

    const observer = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
            if (!entry.isIntersecting) return;
            animate(entry.target);
            observer.unobserve(entry.target);
        });
    }, { threshold: 0.35 });

    counters.forEach(function(el) {
        observer.observe(el);
    });
}

function initializeTaskWorkflow() {
    const selects = document.querySelectorAll('.js-task-status');
    if (!selects.length) return;

    const container = document.querySelector('.main-content');
    const csrfToken = container && container.dataset ? container.dataset.csrfToken : '';

    const getBadgeClass = function(status) {
        switch (status) {
            case 'pending':
                return 'bg-warning';
            case 'in_progress':
                return 'bg-primary';
            case 'completed':
                return 'bg-success';
            default:
                return 'bg-warning';
        }
    };

    const getBadgeText = function(status) {
        switch (status) {
            case 'pending':
                return 'Pending';
            case 'in_progress':
                return 'In Progress';
            case 'completed':
                return 'Completed';
            default:
                return 'Pending';
        }
    };

    const updateBadge = function(badge, status) {
        if (!badge) return;
        badge.classList.remove('bg-warning', 'bg-primary', 'bg-success');
        badge.classList.add(getBadgeClass(status));
        badge.textContent = getBadgeText(status);
    };

    selects.forEach(function(select) {
        select.addEventListener('change', async function() {
            const taskId = select.dataset.taskId;
            const newStatus = select.value;
            const row = select.closest('.js-task-row');
            const badge = row ? row.querySelector('.js-task-badge') : null;
            const updatedEl = row ? row.querySelector('.js-task-updated') : null;

            const previousStatus = select.dataset.currentStatus || '';
            const previousBadgeText = badge ? badge.textContent : '';
            const previousUpdatedText = updatedEl ? updatedEl.textContent : '';

            select.disabled = true;
            select.classList.add('is-loading');

            updateBadge(badge, newStatus);
            select.dataset.currentStatus = newStatus;

            if (!csrfToken) {
                if (badge) badge.textContent = previousBadgeText;
                updateBadge(badge, previousStatus);
                if (updatedEl) updatedEl.textContent = previousUpdatedText;
                select.value = previousStatus || 'pending';
                select.disabled = false;
                select.classList.remove('is-loading');
                showAlert('danger', 'Security token missing. Please refresh the page.');
                return;
            }

            try {
                const formData = new FormData();
                formData.append('action', 'update_task_status');
                formData.append('task_id', taskId);
                formData.append('status', newStatus);
                formData.append('csrf_token', csrfToken);

                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData
                });

                const result = await response.json();
                if (!result || !result.success) {
                    throw new Error(result && result.message ? result.message : 'Failed to update task status');
                }

                if (updatedEl && result.updated_at) {
                    updatedEl.textContent = 'Updated ' + result.updated_at;
                }

                showAlert('success', result.message || 'Task status updated successfully');
            } catch (err) {
                updateBadge(badge, previousStatus);
                if (badge && previousBadgeText) badge.textContent = previousBadgeText;
                if (updatedEl) updatedEl.textContent = previousUpdatedText;
                select.value = previousStatus || 'pending';
                select.dataset.currentStatus = previousStatus || '';
                showAlert('danger', err && err.message ? err.message : 'Failed to update task status');
            } finally {
                select.disabled = false;
                select.classList.remove('is-loading');
            }
        });
    });
}

// Location services
function getCurrentLocation() {
    return new Promise(function(resolve, reject) {
        console.log('[getCurrentLocation] Starting');
        if (navigator.geolocation) {
            console.log('[getCurrentLocation] Geolocation is supported');
            navigator.geolocation.getCurrentPosition(
                function(position) {
                    console.log('[getCurrentLocation] Success:', {
                        latitude: position.coords.latitude,
                        longitude: position.coords.longitude
                    });
                    resolve({
                        latitude: position.coords.latitude,
                        longitude: position.coords.longitude
                    });
                },
                function(error) {
                    console.error('[getCurrentLocation] Error:', error);
                    // For testing/fallback: return dummy coordinates
                    console.warn('[getCurrentLocation] Using fallback coordinates');
                    resolve({
                        latitude: 0.0,
                        longitude: 0.0
                    });
                },
                {
                    enableHighAccuracy: true,
                    timeout: 10000,
                    maximumAge: 0
                }
            );
        } else {
            console.error('[getCurrentLocation] Geolocation not supported');
            // Fallback
            console.warn('[getCurrentLocation] Using fallback coordinates');
            resolve({
                latitude: 0.0,
                longitude: 0.0
            });
        }
    });
}

// Camera access for selfie
function captureSelfie() {
    return new Promise(function(resolve, reject) {
        if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
            navigator.mediaDevices.getUserMedia({ video: true })
                .then(function(stream) {
                    const video = document.createElement('video');
                    video.srcObject = stream;
                    video.play();
                    
                    // Create canvas for capture
                    const canvas = document.createElement('canvas');
                    canvas.width = video.videoWidth;
                    canvas.height = video.videoHeight;
                    const context = canvas.getContext('2d');
                    
                    // Capture after a short delay
                    setTimeout(function() {
                        context.drawImage(video, 0, 0);
                        const imageData = canvas.toDataURL('image/jpeg');
                        
                        // Stop the stream
                        stream.getTracks().forEach(track => track.stop());
                        
                        resolve(imageData);
                    }, 1000);
                })
                .catch(function(error) {
                    reject(error);
                });
        } else {
            reject(new Error('Camera access is not supported.'));
        }
    });
}

// Attendance functions
async function markAttendance(type) {
    try {
        // Show loading
        showLoading();
        
        // Get current location
        const location = await getCurrentLocation();
        
        // Capture selfie
        const selfie = await captureSelfie();
        
        // Prepare form data
        const formData = new FormData();
        formData.append('type', type);
        formData.append('latitude', location.latitude);
        formData.append('longitude', location.longitude);
        formData.append('image', dataURLtoFile(selfie, 'attendance.jpg'));
        
        // Submit attendance
        const response = await fetch('attendance_process.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showAlert('success', result.message);
            setTimeout(() => window.location.reload(), 2000);
        } else {
            showAlert('danger', result.message);
        }
    } catch (error) {
        showAlert('danger', 'Error: ' + error.message);
    } finally {
        hideLoading();
    }
}

// Helper function to convert dataURL to File
function dataURLtoFile(dataurl, filename) {
    console.log('[dataURLtoFile] called with dataurl length:', dataurl?.length);
    const arr = dataurl.split(',');
    const mimeMatch = arr[0].match(/:(.*?);/);
    const mime = mimeMatch ? mimeMatch[1] : 'image/jpeg';
    const bstr = atob(arr[1] || '');
    let n = bstr.length;
    const u8arr = new Uint8Array(n);
    
    while(n--) {
        u8arr[n] = bstr.charCodeAt(n);
    }
    
    console.log('[dataURLtoFile] returning file with mime:', mime);
    return new File([u8arr], filename, {type: mime});
}

// AJAX functions
function ajaxRequest(url, method, data, callback) {
    const xhr = new XMLHttpRequest();
    xhr.open(method, url, true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            if (xhr.status === 200) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    callback(response);
                } catch (e) {
                    callback({success: false, message: 'Invalid response'});
                }
            } else {
                callback({success: false, message: 'Request failed'});
            }
        }
    };
    
    xhr.send(data);
}

// Alert functions
function showAlert(type, message) {
    const alertHtml = `
        <div class="alert alert-${type} alert-dismissible fade show" role="alert">
            <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'danger' ? 'exclamation-circle' : 'info-circle'} me-2"></i>
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    `;
    
    let alertContainer = document.getElementById('alertContainer');
    if (!alertContainer) {
        alertContainer = document.createElement('div');
        alertContainer.id = 'alertContainer';
        const mainContent = document.querySelector('.main-content');
        if (mainContent) {
            mainContent.insertBefore(alertContainer, mainContent.firstChild);
        } else {
            document.body.insertBefore(alertContainer, document.body.firstChild);
        }
    }
    alertContainer.innerHTML = alertHtml;

    const alert = alertContainer.querySelector('.alert');
    if (alert) {
        alert.classList.add('animate-in');
        if (type === 'danger') {
            alert.classList.add('shake');
        }
    }
    
    // Auto dismiss after 5 seconds
    setTimeout(() => {
        const alertToClose = alertContainer.querySelector('.alert');
        if (alertToClose) {
            const bsAlert = new bootstrap.Alert(alertToClose);
            bsAlert.close();
        }
    }, 5000);
}

// Loading functions
function showLoading() {
    let loader = document.getElementById('globalLoader');
    if (!loader) {
        loader = document.createElement('div');
        loader.id = 'globalLoader';
        loader.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 10000;
        `;
        loader.innerHTML = `
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        `;
        document.body.appendChild(loader);
    }
    loader.style.display = 'flex';
}

function hideLoading() {
    const loader = document.getElementById('globalLoader');
    if (loader) {
        loader.style.display = 'none';
    }
}

// PWA: Service Worker Registration + Install Prompt
(function() {
    console.log('[PWA] Initializing...');

    // ── Derive the base path dynamically ─────────────────────────────────
    function getSwUrl() {
        if (window.SITE_URL) {
            const swUrl = window.SITE_URL + 'sw.js';
            console.log('[PWA] Using SITE_URL for SW:', swUrl);
            return swUrl;
        }
        // Fallback if SITE_URL isn't available
        var path = window.location.pathname;
        var basePath = '';
        if (path.includes('/Backup_Files/')) {
            basePath = '/Backup_Files/';
        } else if (path.includes('/admin/')) {
            basePath = path.substring(0, path.indexOf('/admin/') + 1);
        } else if (path.includes('/employee/')) {
            basePath = path.substring(0, path.indexOf('/employee/') + 1);
        } else {
            basePath = '/';
        }
        const swUrl = basePath + 'sw.js';
        console.log('[PWA] Using fallback for SW:', swUrl);
        return swUrl;
    }

    var swUrl = getSwUrl();

    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function() {
            console.log('[PWA] Registering service worker...');
            navigator.serviceWorker.register(swUrl, { scope: swUrl.replace('sw.js', '') })
                .then(function(reg) {
                    console.log('[PWA] SW registered. Scope:', reg.scope);
                    reg.addEventListener('updatefound', function() {
                        console.log('[PWA] New SW found');
                    });
                })
                .catch(function(err) {
                    console.error('[PWA] SW registration failed:', err);
                });
        });
    } else {
        console.warn('[PWA] Service workers not supported');
    }

    // ── Install Prompt Capture ────────────────────────────────────────────
    var deferredPrompt = null;

    // Function to set button states
    function setInstallButtonState(state) {
        document.querySelectorAll('[data-pwa-install], #installBtn, .js-pwa-install, #pwa-install-btn-admin').forEach(function(btn) {
            console.log('[PWA] Setting button state:', state, 'for', btn);
            switch(state) {
                case 'checking':
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Checking...';
                    break;
                case 'ready':
                    btn.disabled = false;
                    btn.classList.remove('disabled');
                    if (btn.dataset.readyText) {
                        btn.innerHTML = btn.dataset.readyText;
                    } else {
                        btn.innerHTML = '<i class="fas fa-download me-2"></i>Install App';
                    }
                    break;
                case 'installing':
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Installing...';
                    break;
                case 'installed':
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fas fa-check me-2"></i>App Installed!';
                    break;
                case 'unavailable':
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fas fa-info-circle me-2"></i>Install Unavailable';
                    break;
                case 'failed':
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-redo me-2"></i>Try Again';
                    break;
            }
        });
    }

    // Function to add click listeners to all install buttons
    function addInstallButtonListeners() {
        console.log('[PWA] Adding click listeners to install buttons');
        document.querySelectorAll('[data-pwa-install], #installBtn, .js-pwa-install, #pwa-install-btn-admin').forEach(function(btn) {
            btn.addEventListener('click', handleInstallClick);
        });
    }

    // Click handler for install buttons
    function handleInstallClick(e) {
        console.log('[PWA] Install button clicked! Event:', e);
        e.preventDefault();
        window.triggerPWAInstall();
    }

    // Initialize buttons to checking state first
    document.addEventListener('DOMContentLoaded', function() {
        console.log('[PWA] DOMContentLoaded fired');
        setInstallButtonState('checking');
        addInstallButtonListeners();
    });

    // Also add listeners on window load just to be safe
    window.addEventListener('load', function() {
        console.log('[PWA] window.load fired');
        addInstallButtonListeners();
    });

    // Check if already installed
    if (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true) {
        console.log('[PWA] Already installed (running standalone)');
        setTimeout(function() {
            setInstallButtonState('installed');
        }, 500);
    }

    window.addEventListener('beforeinstallprompt', function(e) {
        console.log('[PWA] beforeinstallprompt event captured! Event:', e);
        e.preventDefault();
        deferredPrompt = e;
        console.log('[PWA] Deferred prompt stored successfully! deferredPrompt:', deferredPrompt);
        setInstallButtonState('ready');

        // Also fire the legacy global event for pages that listen directly
        if (window._onBeforeInstallPrompt) {
            window._onBeforeInstallPrompt(e);
        }
    });

    window.addEventListener('appinstalled', function(e) {
        console.log('[PWA] appinstalled event fired! Event:', e);
        deferredPrompt = null;
        setInstallButtonState('installed');
    });

    // If beforeinstallprompt never fires after some time, set state to unavailable
    setTimeout(function() {
        console.log('[PWA] Checking if deferred prompt exists after timeout');
        if (!deferredPrompt && !window.matchMedia('(display-mode: standalone)').matches && !(window.navigator.standalone === true)) {
            console.log('[PWA] Install prompt not available (already installed or criteria not met)');
            setInstallButtonState('unavailable');
        }
    }, 5000);

    // Expose prompt function globally so any page can call it
    window.triggerPWAInstall = function() {
        console.log('[PWA] triggerPWAInstall called');
        if (!deferredPrompt) {
            console.log('[PWA] No deferred prompt available! deferredPrompt is null/undefined');
            // Fallback: show instructions
            var msg = 'To install:\n• Chrome: Menu (⋮) → "Add to Home Screen" or "Install App"\n• Edge: Settings (⋯) → "Install this site as an app"\n• Firefox: Not supported as PWA';
            if (window.customConfirm) {
                customConfirm(msg.replace(/\n/g, '<br>'), null);
            } else {
                alert(msg);
            }
            return;
        }
        console.log('[PWA] Calling deferredPrompt.prompt() NOW!');
        setInstallButtonState('installing');
        deferredPrompt.prompt();
        deferredPrompt.userChoice.then(function(choice) {
            console.log('[PWA] User choice result:', choice.outcome);
            if (choice.outcome === 'accepted') {
                console.log('[PWA] User accepted install');
                setInstallButtonState('installed');
            } else {
                console.log('[PWA] User dismissed install');
                setInstallButtonState('ready');
            }
            deferredPrompt = null;
        }).catch(function(err) {
            console.error('[PWA] Install prompt failed with error:', err);
            setInstallButtonState('failed');
            deferredPrompt = null;
        });
    };
})();


function showLoading() {
    const loadingHtml = `
        <div class="loading-overlay">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', loadingHtml);
}

function hideLoading() {
    const loadingOverlay = document.querySelector('.loading-overlay');
    if (loadingOverlay) {
        loadingOverlay.remove();
    }
}

// Form validation
function validateForm(formId) {
    const form = document.getElementById(formId);
    if (!form) return false;
    
    const inputs = form.querySelectorAll('input[required], select[required], textarea[required]');
    let isValid = true;
    
    inputs.forEach(function(input) {
        if (!input.value.trim()) {
            input.classList.add('is-invalid');
            isValid = false;
        } else {
            input.classList.remove('is-invalid');
        }
    });
    
    return isValid;
}

// Confirm action
function confirmAction(message, callback) {
    customConfirm(message, function() {
        callback();
    });
}

// Format currency
function formatCurrency(amount) {
    return new Intl.NumberFormat('en-IN', {
        style: 'currency',
        currency: 'INR'
    }).format(amount);
}

// Format date
function formatDate(date, format = 'DD/MM/YYYY') {
    const d = new Date(date);
    const day = String(d.getDate()).padStart(2, '0');
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const year = d.getFullYear();
    
    switch(format) {
        case 'DD/MM/YYYY':
            return `${day}/${month}/${year}`;
        case 'YYYY-MM-DD':
            return `${year}-${month}-${day}`;
        default:
            return d.toLocaleDateString();
    }
}

// Print function
function printElement(elementId) {
    const element = document.getElementById(elementId);
    if (!element) return;
    
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <html>
            <head>
                <title>Print</title>
                <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
                <style>
                    body { padding: 20px; }
                    .no-print { display: none; }
                </style>
            </head>
            <body>
                ${element.innerHTML}
            </body>
        </html>
    `);
    printWindow.document.close();
    printWindow.print();
}

// Export to CSV
function exportToCSV(tableId, filename) {
    const table = document.getElementById(tableId);
    if (!table) return;
    
    let csv = [];
    const rows = table.querySelectorAll('tr');
    
    rows.forEach(function(row) {
        const cols = row.querySelectorAll('td, th');
        const rowData = [];
        
        cols.forEach(function(col) {
            rowData.push('"' + col.textContent.trim() + '"');
        });
        
        csv.push(rowData.join(','));
    });
    
    const csvContent = csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    
    const a = document.createElement('a');
    a.setAttribute('hidden', '');
    a.setAttribute('href', url);
    a.setAttribute('download', filename);
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    
    window.URL.revokeObjectURL(url);
}

// Mobile sidebar toggle
function toggleSidebar(forceClose = false) {
    const sidebar = document.querySelector('.sidebar');
    if (sidebar) {
        const backdrop = document.querySelector('.sidebar-backdrop');
        const shouldShow = forceClose ? false : !sidebar.classList.contains('show');
        sidebar.classList.toggle('show', shouldShow);
        if (backdrop) {
            backdrop.classList.toggle('show', shouldShow);
        }
        document.body.classList.toggle('ems-sidebar-open', shouldShow);
    }
}

// Auto-refresh functionality
function startAutoRefresh(interval, callback) {
    setInterval(callback, interval);
}

// Search functionality
function searchTable(tableId, searchInput) {
    const table = document.getElementById(tableId);
    const input = document.getElementById(searchInput);
    
    if (!table || !input) return;
    
    input.addEventListener('keyup', function() {
        const filter = input.value.toLowerCase();
        const rows = table.querySelectorAll('tbody tr');
        
        rows.forEach(function(row) {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(filter) ? '' : 'none';
        });
    });
}

function initializeAutoSubmitFilters() {
    // Disabled auto-submit filters to require explicit "Apply Filter" button click
    // document.querySelectorAll('form.js-auto-submit').forEach(function(form) {
    //     const handler = function(e) {
    //         const el = e.target;
    //         if (!el) return;
    //         if (el.matches('input[type="text"], input[type="search"]')) return;
    //         form.requestSubmit ? form.requestSubmit() : form.submit();
    //     };
    //     form.querySelectorAll('select, input[type="date"], input[type="month"], input[type="number"], input[type="checkbox"], input[type="radio"]').forEach(function(el) {
    //         el.addEventListener('change', handler);
    //     });
    // });
}

function initializeSearchableSelects() {
    document.querySelectorAll('select.js-searchable-select').forEach(function(select) {
        if (select.dataset.searchableInit === '1') return;
        select.dataset.searchableInit = '1';

        const wrapper = document.createElement('div');
        wrapper.className = 'select-search-wrap';

        const input = document.createElement('input');
        input.type = 'search';
        input.className = 'form-control form-control-sm mb-2';
        input.placeholder = 'Search...';
        input.autocomplete = 'off';

        select.parentNode.insertBefore(wrapper, select);
        wrapper.appendChild(input);
        wrapper.appendChild(select);

        const allOptions = Array.from(select.options);
        input.addEventListener('input', function() {
            const q = (input.value || '').trim().toLowerCase();
            allOptions.forEach(function(opt) {
                if (!q) {
                    opt.hidden = false;
                    return;
                }
                const txt = (opt.textContent || '').toLowerCase();
                opt.hidden = !txt.includes(q);
            });
        });
    });
}

function initializeSmartTables() {
    document.querySelectorAll('table[data-smart-table]').forEach(function(table) {
        if (table.dataset.smartInit === '1') return;
        table.dataset.smartInit = '1';

        const tbody = table.tBodies && table.tBodies[0];
        if (!tbody) return;

        const originalRows = Array.from(tbody.rows);
        let filteredRows = originalRows.slice();
        let sortIndex = -1;
        let sortDir = 'asc';
        let page = 1;
        const pageSizeDefault = parseInt(table.dataset.pageSize || '10', 10);
        let pageSize = Number.isFinite(pageSizeDefault) && pageSizeDefault > 0 ? pageSizeDefault : 10;

        const tools = document.createElement('div');
        tools.className = 'table-tools d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3';

        const left = document.createElement('div');
        left.className = 'd-flex flex-wrap gap-2 align-items-center';

        const right = document.createElement('div');
        right.className = 'd-flex flex-wrap gap-2 align-items-center';

        const search = document.createElement('input');
        search.type = 'search';
        search.className = 'form-control form-control-sm';
        search.placeholder = 'Search table...';

        const pageSizeSelect = document.createElement('select');
        pageSizeSelect.className = 'form-select form-select-sm';
        [10, 25, 50, 100].forEach(function(n) {
            const opt = document.createElement('option');
            opt.value = String(n);
            opt.textContent = String(n) + ' / page';
            if (n === pageSize) opt.selected = true;
            pageSizeSelect.appendChild(opt);
        });

        const exportBtn = document.createElement('button');
        exportBtn.type = 'button';
        exportBtn.className = 'btn btn-sm btn-secondary';
        exportBtn.innerHTML = '<i class="fas fa-download me-2"></i>Export';

        const pager = document.createElement('div');
        pager.className = 'table-pager d-flex gap-2 align-items-center';

        const info = document.createElement('div');
        info.className = 'text-muted small';

        left.appendChild(search);
        left.appendChild(pageSizeSelect);
        right.appendChild(info);
        right.appendChild(exportBtn);
        tools.appendChild(left);
        tools.appendChild(right);

        const container = table.closest('.table-responsive') || table.parentElement;
        if (container && container.parentElement) {
            container.parentElement.insertBefore(tools, container);
            container.parentElement.appendChild(pager);
        }

        const getCellText = function(tr, idx) {
            const cell = tr.cells && tr.cells[idx];
            return cell ? (cell.textContent || '').trim() : '';
        };

        const toComparable = function(value) {
            const v = (value || '').replace(/[₹,]/g, '').trim();
            const n = Number(v);
            if (Number.isFinite(n) && v !== '') return { kind: 'number', val: n };
            const d = Date.parse(value);
            if (!Number.isNaN(d) && /\d{4}|\d{1,2}\s[a-z]{3}/i.test(value)) return { kind: 'date', val: d };
            return { kind: 'text', val: (value || '').toLowerCase() };
        };

        const render = function() {
            tbody.innerHTML = '';
            const total = filteredRows.length;
            const pages = Math.max(1, Math.ceil(total / pageSize));
            if (page > pages) page = pages;
            const start = (page - 1) * pageSize;
            const slice = filteredRows.slice(start, start + pageSize);
            slice.forEach(function(tr) { tbody.appendChild(tr); });

            info.textContent = total ? ('Showing ' + (start + 1) + '–' + (start + slice.length) + ' of ' + total) : 'No results';
            renderPager(pages);
        };

        const renderPager = function(pages) {
            pager.innerHTML = '';
            pager.classList.add('mt-2', 'justify-content-center');

            const makeBtn = function(label, disabled, onClick, active, isPrev = false, isNext = false) {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'pagination-btn ' + (active ? 'active' : '') + (disabled ? ' disabled' : '');
                if (isPrev) {
                    b.innerHTML = '<i class="fas fa-chevron-left me-1"></i>' + label;
                } else if (isNext) {
                    b.innerHTML = label + '<i class="fas fa-chevron-right ms-1"></i>';
                } else {
                    b.textContent = label;
                }
                if (disabled) b.disabled = true;
                b.addEventListener('click', onClick);
                return b;
            };

            pager.appendChild(makeBtn('Previous', page <= 1, function() { page = Math.max(1, page - 1); render(); }, false, true, false));

            const maxButtons = 5;
            const half = Math.floor(maxButtons / 2);
            let from = Math.max(1, page - half);
            let to = Math.min(pages, from + maxButtons - 1);
            from = Math.max(1, to - maxButtons + 1);

            for (let p = from; p <= to; p++) {
                pager.appendChild(makeBtn(String(p), false, function() { page = p; render(); }, p === page, false, false));
            }

            pager.appendChild(makeBtn('Next', page >= pages, function() { page = Math.min(pages, page + 1); render(); }, false, false, true));
        };

        const applySearch = function() {
            const q = (search.value || '').trim().toLowerCase();
            filteredRows = !q ? originalRows.slice() : originalRows.filter(function(tr) {
                return (tr.textContent || '').toLowerCase().includes(q);
            });
            page = 1;
            applySort(false);
            render();
        };

        const applySort = function(toggleDir = true) {
            if (sortIndex < 0) return;
            if (toggleDir) sortDir = sortDir === 'asc' ? 'desc' : 'asc';
            filteredRows.sort(function(a, b) {
                const av = toComparable(getCellText(a, sortIndex));
                const bv = toComparable(getCellText(b, sortIndex));
                let cmp = 0;
                if (av.kind === bv.kind) {
                    cmp = av.val < bv.val ? -1 : (av.val > bv.val ? 1 : 0);
                } else {
                    cmp = String(av.val).localeCompare(String(bv.val));
                }
                return sortDir === 'asc' ? cmp : -cmp;
            });
        };

        Array.from(table.tHead ? table.tHead.rows[0].cells : []).forEach(function(th, idx) {
            th.style.cursor = 'pointer';
            th.addEventListener('click', function() {
                if (sortIndex === idx) {
                    applySort(true);
                } else {
                    sortIndex = idx;
                    sortDir = 'asc';
                    applySort(false);
                }
                page = 1;
                render();
            });
        });

        search.addEventListener('input', function() { applySearch(); });
        pageSizeSelect.addEventListener('change', function() {
            const n = parseInt(pageSizeSelect.value, 10);
            if (Number.isFinite(n) && n > 0) {
                pageSize = n;
                page = 1;
                render();
            }
        });

        exportBtn.addEventListener('click', function() {
            const id = table.id || '';
            if (id) {
                exportToCSV(id, (table.dataset.exportName || 'export.csv'));
                return;
            }

            const tempId = 'tmp_export_' + Math.random().toString(16).slice(2);
            table.id = tempId;
            exportToCSV(tempId, (table.dataset.exportName || 'export.csv'));
            table.removeAttribute('id');
        });

        render();
    });
}

// Add loading overlay CSS
const style = document.createElement('style');
style.textContent = `
    .loading-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        display: flex;
        justify-content: center;
        align-items: center;
        z-index: 9999;
    }
    
    .spinner-border {
        width: 3rem;
        height: 3rem;
    }
`;
document.head.appendChild(style);

function customConfirm(message, onConfirm) {
    let modalEl = document.getElementById('customConfirmModal');
    if (!modalEl) {
        const modalHtml = `
            <div class=\"modal fade\" id=\"customConfirmModal\" tabindex=\"-1\" aria-hidden=\"true\">
              <div class=\"modal-dialog modal-dialog-centered\">
                <div class=\"modal-content border-0 shadow\">
                  <div class=\"modal-header bg-danger text-white border-0\">
                    <h5 class=\"modal-title\"><i class=\"fas fa-exclamation-triangle me-2\"></i>Confirm Action</h5>
                    <button type=\"button\" class=\"btn-close btn-close-white\" data-bs-dismiss=\"modal\"></button>
                  </div>
                  <div class=\"modal-body py-4 text-center\">
                    <p class=\"fs-6 mb-0\" id=\"customConfirmMessage\"></p>
                  </div>
                  <div class=\"modal-footer bg-light border-0 justify-content-center\">
                    <button type=\"button\" class=\"btn btn-secondary px-4\" data-bs-dismiss=\"modal\">Cancel</button>
                    <button type=\"button\" class=\"btn btn-danger px-4\" id=\"customConfirmBtn\">Yes, Confirm</button>
                  </div>
                </div>
              </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        modalEl = document.getElementById('customConfirmModal');
    }
    document.getElementById('customConfirmMessage').textContent = message;
    const confirmBtn = document.getElementById('customConfirmBtn');
    const newBtn = confirmBtn.cloneNode(true);
    confirmBtn.parentNode.replaceChild(newBtn, confirmBtn);
    const modal = new bootstrap.Modal(modalEl);
    newBtn.addEventListener('click', function() {
        modal.hide();
        if (typeof onConfirm === 'function') onConfirm();
    });
    modal.show();
}

