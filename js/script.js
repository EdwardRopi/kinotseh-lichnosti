/* ============================================================
   КИНОЦЕХ ЛИЧНОСТИ — скрипты

   Заявки уходят через Formspree — ему не нужны секретные ключи,
   поэтому в клиентском коде их нет и быть не должно.

   Прямая отправка в Telegram убрана намеренно: она требовала
   токена бота прямо в этом файле, то есть в открытом доступе для
   любого посетителя. Если нужны уведомления в Telegram — их
   подключают на стороне сервера либо средствами самого Formspree.
   ============================================================ */

/* Заявки принимает send.php на этом же хостинге. Токены MAX и доступ
   к почте лежат в конфиге выше public_html и в браузер не попадают. */
const SEND_URL = '/send.php';

/* Момент загрузки страницы: по нему сервер отличает человека от бота —
   форму, отправленную за пару секунд, заполнял скрипт. */
const PAGE_LOADED_AT = Date.now();

/* Таблица названий программ здесь больше не нужна: форма отправляет
   идентификатор, а человекочитаемое название подставляет сервер из тех же
   данных, что и карточки. Дубликат в браузере только расходился бы с ними. */

const calmMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

async function sendLead(data) {
    try {
        const response = await fetch(SEND_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                name: data.name,
                phone: data.phone,
                email: data.email,
                childAge: data.childAge,
                program: data.program,
                message: data.message,
                privacyPolicy: data.privacyPolicy,
                website: data.website,
                elapsed: Math.round((Date.now() - PAGE_LOADED_AT) / 1000)
            })
        });

        const result = await response.json().catch(() => null);
        return {
            ok: !!(result && result.ok),
            message: result && result.message ? result.message : null
        };
    } catch (error) {
        console.error('Ошибка отправки заявки:', error);
        return { ok: false, message: null };
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

    /* ---------------- Сцены ----------------
       Каждой секции — имя и грейдинг, как разным сценам в фильме.
       Оттенки держим мизерными: всё, что выше 8% прозрачности,
       начинает съедать контраст текста. */

    const SCENES = {
        home:        { name: 'Начало',      grade: 'rgba(150, 175, 205, 0.035)' },
        about:       { name: 'О школе',     grade: 'rgba(140, 170, 200, 0.05)'  },
        directions:  { name: 'Программы',   grade: 'rgba(232, 213, 172, 0.055)' },
        mentors:     { name: 'Наставники',  grade: 'rgba(6, 9, 6, 0.10)'        },
        application: { name: 'Запись',      grade: 'rgba(201, 242, 58, 0.045)'  },
        contacts:    { name: 'Контакты',    grade: 'rgba(208, 138, 92, 0.05)'   }
    };

    const sceneOrder = Array.prototype.map.call(sections, s => s.getAttribute('id'));
    const tcScene = document.getElementById('tcScene');
    const tcTime  = document.getElementById('tcTime');
    const grade   = document.querySelector('.grade');
    let lastScene = '';

    /* Условный хронометраж: доля прокрутки раскладывается в таймкод
       четырёхминутного ролика на 24 кадрах в секунду. Числа взяты не
       с потолка — 24 кадра это киностандарт, и дробная часть бежит
       достаточно быстро, чтобы счётчик выглядел живым. */
    function timecode(fraction) {
        const totalFrames = Math.round(fraction * 4 * 60 * 24);
        const f = totalFrames % 24;
        const s = Math.floor(totalFrames / 24) % 60;
        const m = Math.floor(totalFrames / (24 * 60)) % 60;
        const h = Math.floor(totalFrames / (24 * 60 * 60));
        const pad = n => String(n).padStart(2, '0');
        return pad(h) + ':' + pad(m) + ':' + pad(s) + ':' + pad(f);
    }

    function onScroll() {
        const y = window.pageYOffset;
        header?.classList.toggle('stuck', y > 10);

        // Плёнка идёт через кадровое окно вместе с прокруткой
        if (!calmMotion) {
            const root = document.documentElement.style;
            root.setProperty('--film', (-y * 0.22).toFixed(1) + 'px');

            /* Фоновые ленты. Разные множители — разная удалённость:
               ближняя идёт быстрее, дальняя отстаёт, и фон перестаёт
               читаться плоским. Средняя движется навстречу остальным,
               иначе три параллельных потока сливаются в один. */
            root.setProperty('--reel-1', (y * 0.10).toFixed(1) + 'px');
            root.setProperty('--reel-2', (-y * 0.16).toFixed(1) + 'px');
            root.setProperty('--reel-3', (y * 0.06).toFixed(1) + 'px');

            /* Доворот на всю страницу — единицы градусов. Больше —
               и ленты начинают вертеться, перетягивая внимание. */
            root.setProperty('--reel-rot', (y * 0.0022).toFixed(3) + 'deg');
        }

        /* Доля пройденного. Знаменатель — высота документа минус экран:
           полоса должна заполниться ровно в конце страницы, а не тогда,
           когда низ экрана коснётся последнего пикселя. */
        const scrollable = document.documentElement.scrollHeight - window.innerHeight;
        const progress = scrollable > 0 ? Math.min(1, y / scrollable) : 0;
        document.documentElement.style.setProperty('--progress', progress.toFixed(4));

        let current = '';
        sections.forEach(s => {
            if (y >= s.offsetTop - 200) current = s.getAttribute('id');
        });

        navLinks.forEach(link => {
            link.classList.toggle('on', link.getAttribute('href') === '#' + current);
        });

        /* Таймкод и номер сцены */
        if (tcTime) tcTime.textContent = timecode(progress);

        if (current && current !== lastScene) {
            lastScene = current;
            const scene = SCENES[current];
            const no = String(sceneOrder.indexOf(current) + 1).padStart(2, '0');

            if (tcScene) {
                tcScene.textContent = 'СЦ. ' + no + (scene ? ' · ' + scene.name : '');
            }
            if (grade && scene) {
                document.documentElement.style.setProperty('--grade', scene.grade);
            }
        }

        ticking = false;
    }

    window.addEventListener('scroll', () => {
        if (!ticking) {
            ticking = true;
            requestAnimationFrame(onScroll);
        }
    }, { passive: true });

    onScroll();

    /* ---------------- Краевая маркировка плёнки ----------------
       Строка, какую печатают вдоль края настоящей плёнки: марка,
       тип эмульсии, номер кадра. Собираем в скрипте — в исходнике
       страницы сотне повторяющихся кодов делать нечего.

       Номера идут подряд и не повторяются на разных лентах: у каждой
       свой отсчёт, как у разных бобин. */

    document.querySelectorAll('.reels i').forEach((band, bandIndex) => {
        const marks = [];
        for (let n = 1; n <= 46; n++) {
            const frame = String(n + bandIndex * 46).padStart(2, '0');
            marks.push('КЦЛ 5219 ' + frame + 'A KRD');
        }
        const label = document.createElement('b');
        label.textContent = marks.join('   ');
        band.appendChild(label);
    });

    /* ---------------- Появление блоков ---------------- */

    const revealItems = document.querySelectorAll('.reveal');

    /* Соседи по контейнеру выезжают не одновременно, а лесенкой.
       Индекс считаем среди таких же .reveal внутри общего родителя,
       иначе задержку получили бы и одиночные блоки — без всякого смысла.
       Потолок в четыре шага: дальше ожидание начинает раздражать. */
    revealItems.forEach(el => {
        const siblings = el.parentElement
            ? Array.prototype.filter.call(el.parentElement.children, n => n.classList.contains('reveal'))
            : [];
        if (siblings.length > 1) {
            el.style.setProperty('--d', Math.min(siblings.indexOf(el), 4));
        }
    });

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

    /* ---------------- Хлопушка срабатывает в кадре ---------------- */
    /* На телефоне слейт уходит ниже первого экрана, поэтому запуск
       по таймеру там просто пропал бы мимо зрителя. */

    const slate = document.getElementById('slate');

    if (slate) {
        if ('IntersectionObserver' in window && !calmMotion) {
            const clapIO = new IntersectionObserver((entries, obs) => {
                entries.forEach(entry => {
                    if (!entry.isIntersecting) return;
                    entry.target.classList.add('go');
                    obs.unobserve(entry.target);
                });
            }, { threshold: 0.35 });

            clapIO.observe(slate);
        } else {
            slate.classList.add('go');
        }
    }

    /* ---------------- Карта ----------------
       Карта видна сразу, без заглушки. Но до первого нажатия она не
       принимает касания: иначе палец над ней листает карту вместо
       страницы, а колесо мыши застревает посреди прокрутки.
       Одно нажатие — и карта становится обычной, с перетаскиванием
       и масштабом. */

    const mapBox = document.querySelector('.map');
    mapBox?.addEventListener('click', function () {
        mapBox.classList.add('live');
    });

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

    /* ---------------- Маска телефона ----------------
       Прежняя версия накапливала семёрки: она вырезала из строки всё,
       кроме цифр, но «7» из префикса «+7» — тоже цифра, и она оставалась
       в наборе. Дальше префикс приписывался заново, и каждое нажатие
       добавляло ещё одну семёрку.

       Здесь строка каждый раз собирается заново из чистых цифр, а код
       страны хранится отдельно от номера и в набор не подмешивается. */

    function formatPhone(raw) {
        let digits = raw.replace(/\D/g, '');
        if (!digits) return '';

        /* Первая цифра — код страны. Восьмёрку приводим к семёрке,
           а если человек начал сразу с кода оператора — подставляем сами. */
        if (digits[0] === '8') digits = '7' + digits.slice(1);
        else if (digits[0] !== '7') digits = '7' + digits;

        const rest = digits.slice(1, 11);

        let out = '+7';
        if (rest.length)      out += ' (' + rest.slice(0, 3);
        if (rest.length >= 3) out += ')';
        if (rest.length > 3)  out += ' ' + rest.slice(3, 6);
        if (rest.length > 6)  out += '-' + rest.slice(6, 8);
        if (rest.length > 8)  out += '-' + rest.slice(8, 10);
        return out;
    }

    if (phone) {
        phone.placeholder = '+7 (999) 123-45-67';
        phone.setAttribute('inputmode', 'tel');
        phone.setAttribute('autocomplete', 'tel');

        phone.addEventListener('input', function (e) {
            e.target.value = formatPhone(e.target.value);
        });

        /* Пустое поле не трогаем: пусть работает подсказка и проверка
           обязательности. Один тап по полю не должен вписывать «+7». */
        phone.addEventListener('focus', function (e) {
            if (e.target.value.trim() === '') return;
            e.target.value = formatPhone(e.target.value);
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

    /* ---------------- Обводка жирным карандашом ----------------
       В каждую карточку кладём контур будущего росчерка. Разметку
       держим здесь, а не в HTML: это чистая декорация, и странице
       незачем таскать её в исходнике.

       Контур намеренно незамкнутый и слегка кривой — ровный овал
       читается как рамка, а не как след грифеля. */

    const MARK_PATH = 'M8,52 C7,24 30,9 53,9 C79,9 94,25 93,50 C92,76 71,93 47,92 ' +
                      'C23,91 8,75 8,50 C8,40 10,31 15,25';

    document.querySelectorAll('.tier').forEach(tier => {
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('class', 'tier-mark');
        svg.setAttribute('viewBox', '0 0 100 100');
        /* Растягиваем по форме карточки, а не вписываем квадрат */
        svg.setAttribute('preserveAspectRatio', 'none');
        svg.setAttribute('aria-hidden', 'true');

        const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path.setAttribute('d', MARK_PATH);
        svg.appendChild(path);
        tier.appendChild(svg);
    });

    /* Выбор программы кликом по уровню в карточке */
    document.querySelectorAll('.tier').forEach(tier => {
        tier.addEventListener('click', function () {
            const value = this.dataset.program;
            if (!value || !program) return;

            program.value = value;
            program.dispatchEvent(new Event('change'));

            document.querySelectorAll('.tier.picked').forEach(el => el.classList.remove('picked'));

            /* Перерисовка с нуля: без сброса повторный выбор той же
               карточки не проигрывает росчерк заново. */
            const mark = this.querySelector('.tier-mark path');
            if (mark) {
                mark.style.animation = 'none';
                void mark.offsetWidth;
                mark.style.animation = '';
            }

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
            privacyPolicy: document.getElementById('privacyPolicy').checked,
            website: document.getElementById('website')?.value || ''
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

        /* Недобранный номер выглядит заполненным, но перезвонить по нему
           нельзя — проверяем длину, а не только «поле не пустое». */
        if (!missing.length && data.phone.replace(/\D/g, '').length !== 11) {
            markBad('phone');
            say('Проверьте номер телефона: нужно 11 цифр', 'err');
            return;
        }

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

        const sent = await sendLead(data);

        if (sent.ok) {
            /* Подтверждение подаём слейтом, а не строчкой: заявка ушла —
               хлопушка захлопнулась, дубль принят. Тот же элемент, что
               встречает на первом экране, закрывает историю в конце. */
            if (formMsg) {
                formMsg.className = 'form-msg ok slate-ok';
                formMsg.innerHTML = '';

                const clap = document.createElement('span');
                clap.className = 'ok-clap';
                clap.setAttribute('aria-hidden', 'true');

                const head = document.createElement('span');
                head.className = 'ok-head';
                head.textContent = 'Дубль принят';

                const text = document.createElement('span');
                text.className = 'ok-text';
                text.textContent = sent.message || 'Заявка отправлена. Мы свяжемся с вами в течение дня.';

                formMsg.appendChild(clap);
                formMsg.appendChild(head);
                formMsg.appendChild(text);
            }

            form.reset();
            clearBad();
            document.querySelectorAll('.tier.picked').forEach(el => el.classList.remove('picked'));

            /* Слейт висит дольше обычного сообщения: его читают,
               а не просто замечают краем глаза. */
            setTimeout(() => {
                if (!formMsg) return;
                formMsg.className = 'form-msg';
                formMsg.textContent = '';
            }, 9000);
        } else {
            say(sent.message || 'Не удалось отправить. Попробуйте позже или позвоните нам.', 'err');
        }

        if (button) {
            button.disabled = false;
            button.textContent = label;
        }
    });

    /* ---------------- Приглашение на консультацию ----------------
       Окно показывается с небольшой задержкой, а не мгновенно: окно,
       перекрывающее контент сразу после захода, поисковики считают
       навязчивым и понижают сайт в мобильной выдаче.

       Отсчёт хранится в сессии браузера. Если сбрасывать его при каждом
       обновлении страницы, «осталось 15 минут» превращается в очевидную
       декорацию — а так предложение ведёт себя как настоящее. */

    const promo = document.getElementById('promo');

    if (promo) {
        const SHOW_AFTER = 2500;          // мс до появления
        const LIMIT_MIN  = 15;            // длительность предложения

        const clock = document.getElementById('promoClock');
        const card  = promo.querySelector('.promo-card');
        let tick = null;
        let lastFocus = null;

        /* В приватном режиме обращение к хранилищу бросает исключение,
           и без обёртки оно уронило бы весь скрипт страницы. */
        function remember(key, value) {
            try { sessionStorage.setItem(key, value); } catch (e) {}
        }
        function recall(key) {
            try { return sessionStorage.getItem(key); } catch (e) { return null; }
        }

        function deadline() {
            const saved = Number(recall('promo-end'));
            if (saved && saved > Date.now()) return saved;
            if (saved) return saved;                       // уже истёк — так и покажем
            const end = Date.now() + LIMIT_MIN * 60000;
            remember('promo-end', String(end));
            return end;
        }

        function paint() {
            const left = Math.max(0, deadline() - Date.now());
            const total = Math.floor(left / 1000);
            const mm = String(Math.floor(total / 60)).padStart(2, '0');
            const ss = String(total % 60).padStart(2, '0');
            if (clock) clock.textContent = mm + ':' + ss;

            if (left <= 0) {
                promo.classList.add('over');
                if (clock) clock.textContent = '00:00';
                clearInterval(tick);
                tick = null;
            }
        }

        function open() {
            lastFocus = document.activeElement;
            promo.hidden = false;
            document.body.style.overflow = 'hidden';
            paint();
            if (!tick) tick = setInterval(paint, 1000);
            const x = promo.querySelector('.promo-x');
            if (x) x.focus();
        }

        function close() {
            promo.hidden = true;
            document.body.style.overflow = '';
            clearInterval(tick);
            tick = null;
            remember('promo-off', '1');
            if (lastFocus && lastFocus.focus) lastFocus.focus();
        }

        promo.querySelectorAll('[data-promo-close]').forEach(el => {
            el.addEventListener('click', close);
        });

        /* Переход к форме — тоже закрытие: окно своё дело сделало */
        const go = promo.querySelector('.promo-go');
        if (go) go.addEventListener('click', close);

        document.addEventListener('keydown', function (e) {
            if (promo.hidden) return;

            if (e.key === 'Escape') { close(); return; }

            /* Пока окно открыто, Tab не должен уводить фокус на страницу
               под ним: там пользователь его теряет и не может вернуться. */
            if (e.key === 'Tab' && card) {
                const items = card.querySelectorAll('button, a[href], input, select, textarea');
                if (!items.length) return;
                const first = items[0];
                const last = items[items.length - 1];
                if (e.shiftKey && document.activeElement === first) {
                    e.preventDefault();
                    last.focus();
                } else if (!e.shiftKey && document.activeElement === last) {
                    e.preventDefault();
                    first.focus();
                }
            }
        });

        /* Единственная причина не показать окно — его уже закрывали
           в этой сессии. Раньше здесь была ещё проверка на адрес с
           #application, но такой адрес остаётся в строке браузера после
           любого нажатия «Записаться», и окно потом молча не появлялось. */
        if (recall('promo-off') !== '1') setTimeout(open, SHOW_AFTER);
    }

    /* ---------------- Актуальный год в футере ---------------- */

    const year = document.querySelector('.foot-bottom p');
    if (year) {
        year.textContent = year.textContent.replace('2024', new Date().getFullYear());
    }
});
