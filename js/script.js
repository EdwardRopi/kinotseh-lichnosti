/* ============================================================
   КИНОЦЕХ ЛИЧНОСТИ — скрипты

   Заявки уходят через Formspree — ему не нужны секретные ключи,
   поэтому в клиентском коде их нет и быть не должно.

   Прямая отправка в Telegram убрана намеренно: она требовала
   токена бота прямо в этом файле, то есть в открытом доступе для
   любого посетителя. Если нужны уведомления в Telegram — их
   подключают на стороне сервера либо средствами самого Formspree.
   ============================================================ */

const FORMSPREE_URL = 'https://formspree.io/f/xvzwnpgw';

const PROGRAMS = {
    'actor-base': 'Актер: суть (8-12 лет, База)',
    'actor-pro': 'Актер: суть (13-17 лет, Про)',
    'director-base': 'Режиссер: смыслы (8-12 лет, База)',
    'director-pro': 'Режиссер: смыслы (13-17 лет, Про)',
    'podcast': 'Подкаст: голос и влияние (8-15 лет)'
};

const calmMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

async function sendToFormspree(data, programText) {
    try {
        const response = await fetch(FORMSPREE_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                _subject: `Новая заявка с сайта Киноцех личности: ${data.name}`,
                name: data.name,
                phone: data.phone,
                email: data.email,
                childAge: data.childAge,
                program: programText,
                message: data.message || 'Не указано',
                privacyPolicy: data.privacyPolicy ? 'Да' : 'Нет',
                timestamp: new Date().toLocaleString('ru-RU'),
                source: 'kinotseh-website'
            })
        });

        return response.ok;
    } catch (error) {
        console.error('Ошибка отправки заявки:', error);
        return false;
    }
}

document.addEventListener('DOMContentLoaded', function () {

    const header = document.getElementById('header');
    const burger = document.getElementById('burger');
    const nav = document.getElementById('nav');
    const navLinks = nav ? nav.querySelectorAll('.nav-link') : [];

    /* ---------------- Мобильное меню ---------------- */

    function closeNav() {
        burger?.classList.remove('on');
        nav?.classList.remove('open');
        burger?.setAttribute('aria-expanded', 'false');
        burger?.setAttribute('aria-label', 'Открыть меню');
    }

    burger?.addEventListener('click', () => {
        const open = !nav.classList.contains('open');
        burger.classList.toggle('on', open);
        nav.classList.toggle('open', open);
        burger.setAttribute('aria-expanded', String(open));
        burger.setAttribute('aria-label', open ? 'Закрыть меню' : 'Открыть меню');
    });

    nav?.querySelectorAll('a').forEach(a => a.addEventListener('click', closeNav));
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeNav(); });

    /* ---------------- Плавная прокрутка ---------------- */

    function scrollTo(target) {
        if (!target) return;
        const top = target.getBoundingClientRect().top + window.pageYOffset
                    - (header ? header.offsetHeight : 0) - 14;
        window.scrollTo({ top, behavior: calmMotion ? 'auto' : 'smooth' });
    }

    document.querySelectorAll('a[href^="#"]').forEach(a => {
        a.addEventListener('click', function (e) {
            const id = this.getAttribute('href');
            if (!id || id === '#') return;

            const target = document.querySelector(id);
            if (!target) return;

            e.preventDefault();
            scrollTo(target);

            // На file:// pushState может быть запрещён — прокрутка не должна страдать
            try {
                history.pushState(null, '', id);
            } catch (err) {
                /* безопасно игнорируем */
            }
        });
    });

    /* ---------------- Состояние шапки и активный пункт ---------------- */

    const sections = document.querySelectorAll('section[id]');
    let ticking = false;

    function onScroll() {
        const y = window.pageYOffset;
        header?.classList.toggle('stuck', y > 10);

        // Плёнка идёт через кадровое окно вместе с прокруткой
        if (!calmMotion) {
            document.documentElement.style.setProperty('--film', (-y * 0.22).toFixed(1) + 'px');
        }

        let current = '';
        sections.forEach(s => {
            if (y >= s.offsetTop - 200) current = s.getAttribute('id');
        });

        navLinks.forEach(link => {
            link.classList.toggle('on', link.getAttribute('href') === '#' + current);
        });

        ticking = false;
    }

    window.addEventListener('scroll', () => {
        if (!ticking) {
            ticking = true;
            requestAnimationFrame(onScroll);
        }
    }, { passive: true });

    onScroll();

    /* ---------------- Появление блоков ---------------- */

    const revealItems = document.querySelectorAll('.reveal');

    if ('IntersectionObserver' in window && !calmMotion) {
        const io = new IntersectionObserver((entries, obs) => {
            entries.forEach(entry => {
                if (!entry.isIntersecting) return;
                entry.target.classList.add('in');
                obs.unobserve(entry.target);
            });
        }, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });

        revealItems.forEach(el => io.observe(el));
    } else {
        revealItems.forEach(el => el.classList.add('in'));
    }

    /* ---------------- Переключатель возрастной группы ---------------- */
    /* Возраст вынесен из карточек в один переключатель: карточка
       показывает ровно один уровень, а не оба сразу. */

    const ageSwitch = document.querySelector('.switch');
    const allTiers = document.querySelectorAll('.tier');

    function showTier(kind) {
        if (!ageSwitch) return;

        ageSwitch.dataset.active = kind;
        ageSwitch.querySelectorAll('.sw').forEach(btn => {
            btn.setAttribute('aria-pressed', String(btn.dataset.tier === kind));
        });

        allTiers.forEach(tier => {
            // «both» — курс подкаста, он единый для 8–15 лет
            const visible = tier.dataset.tier === kind || tier.dataset.tier === 'both';
            tier.classList.toggle('show', visible);
        });
    }

    ageSwitch?.querySelectorAll('.sw').forEach(btn => {
        btn.addEventListener('click', () => showTier(btn.dataset.tier));
    });

    /* ======================= ФОРМА ======================= */

    const form = document.getElementById('contactForm');
    const formMsg = document.getElementById('formMessage');
    const program = document.getElementById('program');
    const phone = document.getElementById('phone');
    const childAge = document.getElementById('childAge');

    function say(text, kind) {
        if (!formMsg) return;
        formMsg.textContent = text;
        formMsg.className = 'form-msg ' + kind;
    }

    function markBad(id) {
        document.getElementById(id)?.classList.add('bad');
    }

    function clearBad() {
        document.querySelectorAll('.bad').forEach(el => el.classList.remove('bad'));
    }

    ['name', 'phone', 'email', 'childAge', 'program', 'privacyPolicy'].forEach(id => {
        const el = document.getElementById(id);
        el?.addEventListener('input', () => el.classList.remove('bad'));
        el?.addEventListener('change', () => el.classList.remove('bad'));
    });

    /* Маска телефона */
    if (phone) {
        phone.placeholder = '+7 (XXX) XXX-XX-XX';
        phone.addEventListener('input', function (e) {
            let v = e.target.value.replace(/\D/g, '');

            if (v.length > 0) {
                v = '+7 (' + v;
                if (v.length > 7) v = v.slice(0, 7) + ') ' + v.slice(7);
                if (v.length > 12) v = v.slice(0, 12) + '-' + v.slice(12);
                if (v.length > 15) v = v.slice(0, 15) + '-' + v.slice(15);
                if (v.length > 18) v = v.slice(0, 18);
            }

            e.target.value = v;
        });
    }

    /* Возраст: только цифры, допустимый диапазон 8–17 */
    if (childAge) {
        childAge.addEventListener('input', function (e) {
            const v = e.target.value.replace(/\D/g, '').slice(0, 2);
            e.target.value = v;

            const age = parseInt(v, 10);
            childAge.setCustomValidity(
                v && (age < 8 || age > 17) ? 'Возраст ребенка должен быть от 8 до 17 лет' : ''
            );
        });
    }

    /* Подсказка возраста по выбранной программе */
    program?.addEventListener('change', function () {
        if (!childAge || childAge.value) return;

        const hint = {
            'actor-base': '10',
            'director-base': '10',
            'actor-pro': '15',
            'director-pro': '15',
            'podcast': '12'
        }[this.value];

        if (hint) {
            childAge.value = hint;
            childAge.dispatchEvent(new Event('input'));
        }
    });

    /* Выбор программы кликом по уровню в карточке */
    document.querySelectorAll('.tier').forEach(tier => {
        tier.addEventListener('click', function () {
            const value = this.dataset.program;
            if (!value || !program) return;

            program.value = value;
            program.dispatchEvent(new Event('change'));

            document.querySelectorAll('.tier.picked').forEach(el => el.classList.remove('picked'));
            this.classList.add('picked');

            scrollTo(document.getElementById('application'));
        });
    });

    /* Отправка */
    form?.addEventListener('submit', async function (e) {
        e.preventDefault();

        const data = {
            name: document.getElementById('name').value.trim(),
            phone: phone.value.trim(),
            email: document.getElementById('email').value.trim(),
            childAge: childAge.value.trim(),
            program: program.value,
            message: document.getElementById('message').value.trim(),
            privacyPolicy: document.getElementById('privacyPolicy').checked
        };

        clearBad();

        const required = [
            { id: 'name', value: data.name, label: 'Имя' },
            { id: 'phone', value: data.phone, label: 'Телефон' },
            { id: 'email', value: data.email, label: 'Email' },
            { id: 'childAge', value: data.childAge, label: 'Возраст ребенка' },
            { id: 'program', value: data.program, label: 'Программа' }
        ];

        const missing = required.filter(f => !f.value);
        missing.forEach(f => markBad(f.id));

        if (missing.length) {
            say(`Заполните: ${missing.map(f => f.label).join(', ')}`, 'err');
            return;
        }

        const age = parseInt(data.childAge, 10);
        if (isNaN(age) || age < 8 || age > 17) {
            markBad('childAge');
            say('Возраст ребенка должен быть от 8 до 17 лет', 'err');
            return;
        }

        if (!data.privacyPolicy) {
            markBad('privacyPolicy');
            say('Необходимо согласие на обработку персональных данных', 'err');
            return;
        }

        const button = form.querySelector('button[type="submit"]');
        const label = button?.textContent;

        if (button) {
            button.disabled = true;
            button.textContent = 'Отправляем…';
        }
        say('Отправляем заявку…', 'info');

        const sent = await sendToFormspree(data, PROGRAMS[data.program] || data.program);

        if (sent) {
            say('Заявка отправлена. Мы свяжемся с вами в течение дня.', 'ok');
            form.reset();
            clearBad();
            document.querySelectorAll('.tier.picked').forEach(el => el.classList.remove('picked'));
            setTimeout(() => { formMsg.className = 'form-msg'; }, 6000);
        } else {
            say('Не удалось отправить. Попробуйте позже или позвоните нам.', 'err');
        }

        if (button) {
            button.disabled = false;
            button.textContent = label;
        }
    });

    /* ---------------- Актуальный год в футере ---------------- */

    const year = document.querySelector('.foot-bottom p');
    if (year) {
        year.textContent = year.textContent.replace('2024', new Date().getFullYear());
    }
});
