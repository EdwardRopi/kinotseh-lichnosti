/* ============================================================
   КИНОЦЕХ ЛИЧНОСТИ — скрипты
   ============================================================ */

/* ------------------------------------------------------------------
   Заявки уходят через Formspree — ему не нужны секретные ключи,
   поэтому в клиентском коде их нет и быть не должно.

   Прямая отправка в Telegram убрана намеренно: она требовала токена
   бота прямо в этом файле, то есть в открытом доступе для любого
   посетителя. Если нужны уведомления в Telegram — их подключают на
   стороне сервера либо через уведомления самого Formspree.
   ------------------------------------------------------------------ */
const FORMSPREE_URL = 'https://formspree.io/f/xvzwnpgw';

// Соответствие значения программы её читаемому названию
const PROGRAMS = {
    'actor-base': 'Актер: суть (8-12 лет, База)',
    'actor-pro': 'Актер: суть (13-17 лет, Про)',
    'director-base': 'Режиссер: смыслы (8-12 лет, База)',
    'director-pro': 'Режиссер: смыслы (13-17 лет, Про)',
    'podcast': 'Подкаст: голос и влияние (8-15 лет)'
};

const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/* ===================== ОТПРАВКА ЗАЯВКИ ===================== */

async function sendToFormspree(formData, programText) {
    try {
        const response = await fetch(FORMSPREE_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                _subject: `Новая заявка с сайта Киноцех личности: ${formData.name}`,
                name: formData.name,
                phone: formData.phone,
                email: formData.email,
                childAge: formData.childAge,
                program: programText,
                message: formData.message || 'Не указано',
                privacyPolicy: formData.privacyPolicy ? 'Да' : 'Нет',
                timestamp: new Date().toLocaleString('ru-RU'),
                source: 'kinotseh-website'
            })
        });

        return response.ok;
    } catch (error) {
        console.error('Ошибка отправки на Formspree:', error);
        return false;
    }
}

/* ========================= ИНИЦИАЛИЗАЦИЯ ========================= */

document.addEventListener('DOMContentLoaded', function () {

    /* ---------- Шапка: уплотнение при прокрутке ---------- */
    const header = document.getElementById('header');
    const progressBar = document.querySelector('.scroll-progress span');
    const toTop = document.getElementById('toTop');

    /* ---------- Мобильное меню ---------- */
    const hamburger = document.getElementById('hamburger');
    const navMenu = document.getElementById('navMenu');
    const navLinks = document.querySelectorAll('.nav-link');

    function closeMenu() {
        if (!hamburger) return;
        hamburger.classList.remove('active');
        hamburger.setAttribute('aria-expanded', 'false');
        hamburger.setAttribute('aria-label', 'Открыть меню');
        navMenu.classList.remove('active');
        document.body.style.overflow = '';
    }

    if (hamburger) {
        hamburger.addEventListener('click', function () {
            const open = !navMenu.classList.contains('active');
            hamburger.classList.toggle('active', open);
            navMenu.classList.toggle('active', open);
            hamburger.setAttribute('aria-expanded', String(open));
            hamburger.setAttribute('aria-label', open ? 'Закрыть меню' : 'Открыть меню');
            document.body.style.overflow = open ? 'hidden' : '';
        });
    }

    // Закрытие меню при клике по ссылке и по Escape
    navMenu?.querySelectorAll('a').forEach(link => link.addEventListener('click', closeMenu));
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') closeMenu();
    });

    /* ---------- Плавная прокрутка по якорям ---------- */
    function scrollToTarget(target) {
        if (!target) return;
        const headerHeight = header ? header.offsetHeight : 0;
        const top = target.getBoundingClientRect().top + window.pageYOffset - headerHeight - 12;
        window.scrollTo({ top, behavior: prefersReducedMotion ? 'auto' : 'smooth' });
    }

    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            const targetId = this.getAttribute('href');
            if (!targetId || targetId === '#') return;

            const target = document.querySelector(targetId);
            if (!target) return;

            e.preventDefault();
            scrollToTarget(target);

            // На file:// pushState может быть запрещён — прокрутка не должна страдать
            try {
                history.pushState(null, '', targetId);
            } catch (err) {
                /* безопасно игнорируем */
            }
        });
    });

    /* ---------- Подсветка активного пункта меню ---------- */
    const sections = document.querySelectorAll('section[id]');

    function highlightNavLink() {
        const scrollY = window.pageYOffset;
        let current = '';

        sections.forEach(section => {
            const top = section.offsetTop - 180;
            if (scrollY >= top) current = section.getAttribute('id');
        });

        navLinks.forEach(link => {
            link.classList.toggle('active', link.getAttribute('href') === '#' + current);
        });
    }

    /* ---------- Единый обработчик прокрутки ---------- */
    const parallaxItems = document.querySelectorAll('[data-parallax]');
    let ticking = false;

    function onScroll() {
        const y = window.pageYOffset;

        // Шапка
        header?.classList.toggle('scrolled', y > 30);

        // Полоса прогресса
        if (progressBar) {
            const max = document.documentElement.scrollHeight - window.innerHeight;
            progressBar.style.setProperty('--p', (max > 0 ? (y / max) * 100 : 0) + '%');
        }

        // Кнопка «наверх»
        toTop?.classList.toggle('show', y > 600);

        // Параллакс сфер в герое
        if (!prefersReducedMotion && y < window.innerHeight * 1.5) {
            parallaxItems.forEach(el => {
                const rate = parseFloat(el.dataset.parallax) || 0;
                el.style.translate = `0 ${y * rate}px`;
            });
        }

        highlightNavLink();
        ticking = false;
    }

    window.addEventListener('scroll', () => {
        if (!ticking) {
            ticking = true;
            requestAnimationFrame(onScroll);
        }
    }, { passive: true });

    onScroll();

    /* ---------- Появление элементов при прокрутке ---------- */
    const revealItems = document.querySelectorAll('.reveal');
    revealItems.forEach(el => {
        if (el.dataset.delay) el.style.setProperty('--d', el.dataset.delay);
    });

    if ('IntersectionObserver' in window && !prefersReducedMotion) {
        const revealObserver = new IntersectionObserver((entries, obs) => {
            entries.forEach(entry => {
                if (!entry.isIntersecting) return;
                entry.target.classList.add('visible');
                obs.unobserve(entry.target);
            });
        }, { threshold: 0.12, rootMargin: '0px 0px -60px 0px' });

        revealItems.forEach(el => revealObserver.observe(el));
    } else {
        revealItems.forEach(el => el.classList.add('visible'));
    }

    /* ---------- Счётчики в герое ---------- */
    const counters = document.querySelectorAll('.counter');

    function runCounter(el) {
        const target = parseInt(el.dataset.count, 10) || 0;

        if (prefersReducedMotion) {
            el.textContent = String(target);
            return;
        }

        const duration = 1400;
        const start = performance.now();

        function step(now) {
            const p = Math.min((now - start) / duration, 1);
            // Плавное замедление к концу
            const eased = 1 - Math.pow(1 - p, 3);
            el.textContent = String(Math.round(target * eased));
            if (p < 1) requestAnimationFrame(step);
        }

        requestAnimationFrame(step);
    }

    if ('IntersectionObserver' in window) {
        const counterObserver = new IntersectionObserver((entries, obs) => {
            entries.forEach(entry => {
                if (!entry.isIntersecting) return;
                runCounter(entry.target);
                obs.unobserve(entry.target);
            });
        }, { threshold: 0.5 });

        counters.forEach(el => counterObserver.observe(el));
    } else {
        counters.forEach(runCounter);
    }

    /* ---------- Эффект «прожектор» на карточках ---------- */
    const spotlights = document.querySelectorAll('.spotlight');
    spotlights.forEach(card => {
        card.addEventListener('pointermove', e => {
            const r = card.getBoundingClientRect();
            card.style.setProperty('--mx', ((e.clientX - r.left) / r.width) * 100 + '%');
            card.style.setProperty('--my', ((e.clientY - r.top) / r.height) * 100 + '%');
        });
    });

    /* ---------- Неоновое пятно за курсором ---------- */
    const cursorGlow = document.querySelector('.cursor-glow');
    if (cursorGlow && window.matchMedia('(pointer: fine)').matches && !prefersReducedMotion) {
        let glowRaf = false;
        let gx = 0, gy = 0;

        window.addEventListener('pointermove', e => {
            gx = e.clientX;
            gy = e.clientY;
            cursorGlow.classList.add('on');

            if (!glowRaf) {
                glowRaf = true;
                requestAnimationFrame(() => {
                    cursorGlow.style.setProperty('--cx', gx + 'px');
                    cursorGlow.style.setProperty('--cy', gy + 'px');
                    glowRaf = false;
                });
            }
        }, { passive: true });

        document.addEventListener('pointerleave', () => cursorGlow.classList.remove('on'));
    }

    /* ---------- Магнитные кнопки ---------- */
    if (window.matchMedia('(pointer: fine)').matches && !prefersReducedMotion) {
        document.querySelectorAll('.magnetic').forEach(btn => {
            btn.addEventListener('pointermove', e => {
                const r = btn.getBoundingClientRect();
                const x = (e.clientX - r.left - r.width / 2) * 0.22;
                const y = (e.clientY - r.top - r.height / 2) * 0.32;
                btn.style.setProperty('--tx', x + 'px');
                btn.style.setProperty('--ty', y + 'px');
            });

            btn.addEventListener('pointerleave', () => {
                btn.style.setProperty('--tx', '0px');
                btn.style.setProperty('--ty', '0px');
            });
        });
    }

    /* ---------- Аккордеоны «Образование» ---------- */
    document.querySelectorAll('.acc-trigger').forEach(trigger => {
        trigger.addEventListener('click', () => {
            const expanded = trigger.getAttribute('aria-expanded') === 'true';
            trigger.setAttribute('aria-expanded', String(!expanded));
        });
    });

    /* ================== ФОРМА ЗАЯВКИ ================== */

    const contactForm = document.getElementById('contactForm');
    const formMessage = document.getElementById('formMessage');
    const programSelect = document.getElementById('program');
    const phoneInput = document.getElementById('phone');
    const childAgeInput = document.getElementById('childAge');

    function showFormMessage(text, type) {
        if (!formMessage) return;
        formMessage.textContent = text;
        formMessage.className = 'form-message ' + type;
    }

    function markError(fieldId) {
        document.getElementById(fieldId)?.classList.add('field-error');
    }

    function resetErrors() {
        document.querySelectorAll('.field-error').forEach(el => el.classList.remove('field-error'));
    }

    // Снимаем подсветку ошибки, как только поле правят
    ['name', 'phone', 'email', 'childAge', 'program', 'privacyPolicy'].forEach(id => {
        const el = document.getElementById(id);
        el?.addEventListener('input', () => el.classList.remove('field-error'));
        el?.addEventListener('change', () => el.classList.remove('field-error'));
    });

    /* ---------- Маска телефона ---------- */
    if (phoneInput) {
        phoneInput.addEventListener('input', function (e) {
            let value = e.target.value.replace(/\D/g, '');

            if (value.length > 0) {
                value = '+7 (' + value;
                if (value.length > 7) value = value.slice(0, 7) + ') ' + value.slice(7);
                if (value.length > 12) value = value.slice(0, 12) + '-' + value.slice(12);
                if (value.length > 15) value = value.slice(0, 15) + '-' + value.slice(15);
                if (value.length > 18) value = value.slice(0, 18);
            }

            e.target.value = value;
        });
    }

    /* ---------- Возраст ребёнка: только цифры, 8–17 ---------- */
    if (childAgeInput) {
        childAgeInput.addEventListener('input', function (e) {
            const value = e.target.value.replace(/\D/g, '').slice(0, 2);
            e.target.value = value;

            const age = parseInt(value, 10);
            if (value && (age < 8 || age > 17)) {
                childAgeInput.setCustomValidity('Возраст ребенка должен быть от 8 до 17 лет');
            } else {
                childAgeInput.setCustomValidity('');
            }
        });
    }

    /* ---------- Подсказка возраста по выбранной программе ---------- */
    if (programSelect) {
        programSelect.addEventListener('change', function () {
            if (!childAgeInput || childAgeInput.value) return;

            const suggested = {
                'actor-base': '10',
                'director-base': '10',
                'actor-pro': '15',
                'director-pro': '15',
                'podcast': '12'
            }[this.value];

            if (suggested) {
                childAgeInput.value = suggested;
                childAgeInput.dispatchEvent(new Event('input'));
            }
        });
    }

    /* ---------- Выбор программы кликом по карточке ---------- */
    document.querySelectorAll('.age-group').forEach(group => {
        group.addEventListener('click', function () {
            const value = this.dataset.program;
            if (!value || !programSelect) return;

            programSelect.value = value;
            programSelect.dispatchEvent(new Event('change'));

            // Визуальная обратная связь
            document.querySelectorAll('.age-group.picked').forEach(el => el.classList.remove('picked'));
            this.classList.add('picked');

            scrollToTarget(document.getElementById('application'));
        });
    });

    /* ---------- Отправка формы ---------- */
    if (contactForm) {
        contactForm.addEventListener('submit', async function (e) {
            e.preventDefault();

            const formData = {
                name: document.getElementById('name').value.trim(),
                phone: document.getElementById('phone').value.trim(),
                email: document.getElementById('email').value.trim(),
                childAge: document.getElementById('childAge').value.trim(),
                program: programSelect.value,
                message: document.getElementById('message').value.trim(),
                privacyPolicy: document.getElementById('privacyPolicy').checked
            };

            resetErrors();

            // --- Валидация обязательных полей ---
            const required = [
                { id: 'name', value: formData.name, label: 'Имя родителя' },
                { id: 'phone', value: formData.phone, label: 'Телефон' },
                { id: 'email', value: formData.email, label: 'Email' },
                { id: 'childAge', value: formData.childAge, label: 'Возраст ребенка' },
                { id: 'program', value: formData.program, label: 'Программа' }
            ];

            const missing = required.filter(f => !f.value);
            missing.forEach(f => markError(f.id));

            if (missing.length) {
                showFormMessage(
                    `Пожалуйста, заполните все обязательные поля: ${missing.map(f => f.label).join(', ')}`,
                    'error'
                );
                return;
            }

            // --- Возраст ---
            const age = parseInt(formData.childAge, 10);
            if (isNaN(age) || age < 8 || age > 17) {
                markError('childAge');
                showFormMessage('Возраст ребенка должен быть от 8 до 17 лет', 'error');
                return;
            }

            // --- Согласие на обработку ПД ---
            if (!formData.privacyPolicy) {
                markError('privacyPolicy');
                showFormMessage('Необходимо согласие на обработку персональных данных', 'error');
                return;
            }

            // --- Отправка ---
            const submitBtn = contactForm.querySelector('button[type="submit"]');
            const btnIcon = submitBtn?.querySelector('i');
            const originalIcon = btnIcon?.className;

            submitBtn?.classList.add('is-loading');
            if (btnIcon) btnIcon.className = 'fas fa-circle-notch';
            showFormMessage('Отправка заявки...', 'info');

            const programText = PROGRAMS[formData.program] || formData.program;

            try {
                const sent = await sendToFormspree(formData, programText);

                if (sent) {
                    showFormMessage(
                        '✅ Спасибо! Ваша заявка успешно отправлена. Мы свяжемся с вами в течение дня.',
                        'success'
                    );
                    contactForm.reset();
                    resetErrors();
                    document.querySelectorAll('.age-group.picked').forEach(el => el.classList.remove('picked'));

                    setTimeout(() => { formMessage.className = 'form-message'; }, 6000);
                } else {
                    showFormMessage(
                        '❌ Ошибка отправки. Пожалуйста, попробуйте позже или свяжитесь с нами по телефону.',
                        'error'
                    );
                }
            } catch (error) {
                console.error('Ошибка отправки формы:', error);
                showFormMessage('❌ Ошибка сети. Пожалуйста, проверьте подключение к интернету.', 'error');
            } finally {
                submitBtn?.classList.remove('is-loading');
                if (btnIcon && originalIcon) btnIcon.className = originalIcon;
            }
        });
    }

    /* ---------- Актуальный год в футере ---------- */
    const yearEl = document.querySelector('.footer-bottom p');
    if (yearEl) {
        yearEl.innerHTML = yearEl.innerHTML.replace('2024', new Date().getFullYear());
    }
});
