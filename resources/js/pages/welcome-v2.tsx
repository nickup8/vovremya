import { useEffect, useRef, useState } from 'react';
import { Head } from '@inertiajs/react';
import DemoBookingWidget from '../components/landing/DemoBookingWidget';
import '../../css/welcome-v2.css';

/* IRSI landing v2 — step 1: header, navigation, hero, hero calendar visual.
   Step 3: section 02 (interactive booking demo).
   Step 4: section 03 (lifecycle — между записью и визитом).
   Ported from docs/landing/irsi-landing-2026-09-30-v3.html. */

export default function WelcomeV2() {
    const [menuOpen, setMenuOpen] = useState(false);

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') setMenuOpen(false);
        };
        const desktop = window.matchMedia('(min-width: 901px)');
        const onDesktop = (event: MediaQueryListEvent) => {
            if (event.matches) setMenuOpen(false);
        };

        document.addEventListener('keydown', onKey);
        desktop.addEventListener('change', onDesktop);

        return () => {
            document.removeEventListener('keydown', onKey);
            desktop.removeEventListener('change', onDesktop);
        };
    }, []);

    /* 05: mobile screens carousel — one card per swipe, buttons and counter
       as in the source landing. */
    const clientCarouselRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const root = clientCarouselRef.current;
        if (!root) return;
        const track = root.querySelector<HTMLElement>('.carousel-track');
        const prev = root.querySelector<HTMLButtonElement>('[data-slide="prev"]');
        const next = root.querySelector<HTMLButtonElement>('[data-slide="next"]');
        const count = root.querySelector<HTMLElement>('.carousel-count');
        if (!track || !prev || !next || !count) return;
        const slides = Array.from(track.querySelectorAll<HTMLElement>('.slide'));
        if (slides.length === 0) return;

        const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
        let current = 0;
        let pending = false;

        const left = (index: number) => slides[index].offsetLeft - slides[0].offsetLeft;

        const update = () => {
            current = slides.reduce(
                (best, _slide, index) =>
                    Math.abs(left(index) - track.scrollLeft) < Math.abs(left(best) - track.scrollLeft) ? index : best,
                0,
            );
            count.textContent = `${current + 1} / ${slides.length}`;
            prev.disabled = current === 0;
            next.disabled = current === slides.length - 1;
            pending = false;
        };

        const go = (step: number) => {
            const index = Math.max(0, Math.min(slides.length - 1, current + step));
            track.scrollTo({ left: left(index), behavior: reduced.matches ? 'auto' : 'smooth' });
        };

        const onPrev = () => go(-1);
        const onNext = () => go(1);
        const onScroll = () => {
            if (!pending) {
                pending = true;
                requestAnimationFrame(update);
            }
        };
        const onKeydown = (event: KeyboardEvent) => {
            if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
                event.preventDefault();
                go(event.key === 'ArrowRight' ? 1 : -1);
            }
        };
        const observer = new ResizeObserver(() => {
            track.scrollTo({ left: left(current), behavior: 'auto' });
            update();
        });

        prev.addEventListener('click', onPrev);
        next.addEventListener('click', onNext);
        track.addEventListener('scroll', onScroll, { passive: true });
        track.addEventListener('keydown', onKeydown);
        observer.observe(track);
        update();

        return () => {
            prev.removeEventListener('click', onPrev);
            next.removeEventListener('click', onNext);
            track.removeEventListener('scroll', onScroll);
            track.removeEventListener('keydown', onKeydown);
            observer.disconnect();
        };
    }, []);

    return (
        <>
            <Head title="ИРСИ — онлайн-запись для частных мастеров">
                <meta name="robots" content="noindex,nofollow" />
            </Head>

            <div className="irsi-v2">
                <a className="skip" href="#main">
                    Перейти к содержимому
                </a>

                <header className="header">
                    <div className="wrap header-inner">
                        <a className="brand" href="#" aria-label="ИРСИ — в начало">
                            <img className="logo" src="/images/landing/logo-irsi.svg" alt="ИРСИ" width="132" height="35" />
                        </a>
                        <nav className={`nav${menuOpen ? ' open' : ''}`} id="navigation" aria-label="Основная навигация">
                            <a href="#how" onClick={() => setMenuOpen(false)}>
                                Как работает
                            </a>
                            <a href="#features" onClick={() => setMenuOpen(false)}>
                                Возможности
                            </a>
                            <a href="#pricing" onClick={() => setMenuOpen(false)}>
                                Тарифы
                            </a>
                        </nav>
                        <div className="header-actions">
                            <a className="login" href="/auth/login" data-event="login_click">
                                Вход для мастеров
                            </a>
                            <a className="btn header-cta" href="/auth/login" data-event="cta_header_click">
                                Начать бесплатно
                            </a>
                            <button
                                type="button"
                                className="menu"
                                aria-controls="navigation"
                                aria-expanded={menuOpen}
                                onClick={() => setMenuOpen((open) => !open)}
                            >
                                {menuOpen ? 'Закрыть' : 'Меню'}
                            </button>
                        </div>
                    </div>
                </header>

                <main id="main">
                    <section className="hero wrap">
                        <div className="hero-head grid">
                            <div className="eyebrow">
                                <span></span>Онлайн-запись / Ваше личное дело
                            </div>
                            <h1>
                                Онлайн-запись
                                <br /> для частных мастеров.
                            </h1>
                            <div className="hero-aside">
                                <p>
                                    Вы ведёте своё дело.
                                    <br /> ИРСИ ведёт запись дальше.
                                </p>
                            </div>
                            <p className="hero-under">
                                Запись — только начало. Клиент выбирает услугу и свободное время сам, а ИРСИ ведёт запись дальше:
                                календарь, напоминания, подтверждение и изменения — в одном месте.
                            </p>
                            <div className="hero-action">
                                <a className="btn" href="/auth/login" data-event="cta_hero_click">
                                    Начать бесплатно
                                </a>
                                <span className="caption">Без данных карты</span>
                            </div>
                        </div>

                        <div className="hero-stage">
                            <div className="calendar-stage">
                                <div className="stage-label">
                                    <span>Ваше расписание</span>
                                    <span>В одном месте</span>
                                </div>
                                <div className="calendar-crop">
                                    <img
                                        src="/images/landing/calendar-desktop.svg"
                                        width="1800"
                                        height="757"
                                        alt="Календарь ИРСИ с визитами и перерывами"
                                        loading="eager"
                                    />
                                </div>
                                <div className="mobile-cal">
                                    <img
                                        src="/images/landing/calendar-mobile.svg"
                                        width="650"
                                        height="1007"
                                        alt="Дневной календарь ИРСИ на телефоне"
                                        loading="eager"
                                    />
                                </div>
                            </div>
                            <div className="hero-message">
                                <span>Мастеру / уведомление</span>
                                <h3>
                                    Запись появилась.
                                    <br /> Вы уже знаете.
                                </h3>
                                <img
                                    src="/images/landing/notification-new.svg"
                                    width="692"
                                    height="368"
                                    alt="Реальное уведомление мастера о новой записи"
                                    loading="eager"
                                />
                                <p>Клиент, услуга и время — в уведомлении.</p>
                            </div>
                        </div>

                        <div className="hero-foot">
                            <span>
                                <b>Старт — 0 ₽</b> · без пробного периода
                            </span>
                            <span>Без лимита записей</span>
                            <span>VK / MAX</span>
                        </div>
                    </section>

                    <section className="situation">
                        <div className="wrap grid">
                            <div className="eyebrow">
                                <span>01</span>Знакомая ситуация
                            </div>
                            <div className="situation-content">
                                <h2>
                                    Работа с клиентом.
                                    <br /> И всё, что вокруг неё.
                                </h2>
                                <div className="questions">
                                    <div>
                                        <b>«Когда можно?»</b>
                                        <p>Проверить график и согласовать время.</p>
                                    </div>
                                    <div>
                                        <b>«Мы завтра в силе?»</b>
                                        <p>Напомнить о встрече и получить ответ.</p>
                                    </div>
                                    <div>
                                        <b>«Давайте перенесём»</b>
                                        <p>Изменить запись и обновить расписание.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section className="section wrap" id="how">
                        <div className="section-heading grid">
                            <div className="eyebrow">
                                <span>02</span>Как появляется запись
                            </div>
                            <h2>
                                Клиент выбирает.
                                <br /> Запись появляется у Вас.
                            </h2>
                        </div>
                        <div className="booking-layout">
                            <div className="booking-intro">
                                <p>Поделитесь ссылкой. Клиент сам выберет услугу, дату и свободное время в Вашем графике.</p>
                                <div className="channels">
                                    <span>Ссылка</span>
                                    <span>VK</span>
                                    <span>MAX</span>
                                </div>
                            </div>
                            <div className="booking-gallery booking-demo">
                                <DemoBookingWidget />
                            </div>
                        </div>
                    </section>

                    <section className="section lifecycle" id="after">
                        <div className="wrap">
                            <div className="grid life-intro">
                                <div className="eyebrow">
                                    <span>03</span>Между записью и визитом
                                </div>
                                <h2>
                                    Запись —<br /> только начало.
                                </h2>
                                <p>У записи есть продолжение. ИРСИ напоминает клиенту о встрече и сообщает мастеру о подтверждении.</p>
                            </div>
                            <div className="message-panels">
                                <article className="message-panel">
                                    <span className="message-role">01 / Мастеру</span>
                                    <h3>
                                        Новая запись.<br /> Уведомление Вам.
                                    </h3>
                                    <p>Кто записался, на какую услугу и когда — в одном сообщении.</p>
                                    <div className="message-image">
                                        <img
                                            src="/images/landing/notification-new.svg"
                                            width="692"
                                            height="368"
                                            alt="Сообщение мастеру: у Вас новая запись"
                                            loading="lazy"
                                        />
                                    </div>
                                </article>
                                <article className="message-panel">
                                    <span className="message-role">02 / Клиенту</span>
                                    <h3>
                                        Встреча скоро.<br /> ИРСИ напомнит.
                                    </h3>
                                    <p>Клиент получает напоминание с услугой, временем и адресом.</p>
                                    <div className="message-image">
                                        <img
                                            src="/images/landing/notification-reminder.svg"
                                            width="748"
                                            height="529"
                                            alt="Напоминание клиенту о предстоящем визите"
                                            loading="lazy"
                                        />
                                    </div>
                                </article>
                                <article className="message-panel">
                                    <span className="message-role">03 / Снова мастеру</span>
                                    <h3>
                                        Визит подтверждён.<br /> Вы в курсе.
                                    </h3>
                                    <p>После подтверждения клиента мастер получает уведомление.</p>
                                    <div className="message-image">
                                        <img
                                            src="/images/landing/notification-confirmed.svg"
                                            width="606"
                                            height="260"
                                            alt="Сообщение мастеру: клиент подтвердил визит"
                                            loading="lazy"
                                        />
                                    </div>
                                </article>
                            </div>
                            <div className="life-finish">
                                <p>
                                    Если планы изменились — запись можно перенести или отменить по правилам сервиса. Изменения отражаются в календаре.
                                    <br /> <br /> Напоминания и подтверждение визита входят в тариф «Старт».
                                </p>
                                <a className="btn" href="/auth/login" data-event="cta_mid_click">
                                    Начать бесплатно
                                </a>
                            </div>
                        </div>
                    </section>

                    <section className="section wrap" id="features">
                        <div className="grid calendar-heading">
                            <div>
                                <div className="eyebrow">
                                    <span>04</span>Календарь и график
                                </div>
                                <h2>
                                    Время для клиентов.
                                    <br /> И время для себя.
                                </h2>
                            </div>
                            <p>Ваш график определяет, когда можно записаться. ИРСИ учитывает рабочие часы, периоды и перерывы.</p>
                        </div>
                        <div className="schedule-grid">
                            <figure>
                                <picture>
                                    <source media="(max-width:650px)" srcSet="/images/landing/schedule-mobile.webp" />
                                    <img
                                        src="/images/landing/schedule-desktop.webp"
                                        width="1295"
                                        height="788"
                                        alt="Настройка рабочих часов, перерывов и повторяющихся блокировок"
                                        loading="lazy"
                                    />
                                </picture>
                            </figure>
                            <div className="schedule-text">
                                <article>
                                    <h3>
                                        Рабочие часы
                                        <br /> и периоды
                                    </h3>
                                    <p>Настройте дни и время, в которые принимаете клиентов.</p>
                                </article>
                                <article>
                                    <h3>Разовые и повторяющиеся блокировки</h3>
                                    <p>
                                        Закройте время для личных планов или регулярного перерыва. Всё это доступно в тарифе «Старт».
                                    </p>
                                </article>
                            </div>
                        </div>
                    </section>

                    <section className="section wrap clients">
                        <div className="grid">
                            <div className="client-heading">
                                <div className="eyebrow">
                                    <span>05</span>Клиенты и услуги
                                </div>
                                <h2>
                                    Детали, которые
                                    <br /> не нужно держать
                                    <br /> в голове.
                                </h2>
                                <p>Карточки клиентов, история визитов и заметки. Услуги, цены и длительность — рядом с Вашим расписанием.</p>
                            </div>
                            <div className="client-papers">
                                <figure>
                                    <figcaption>Клиент / история работы</figcaption>
                                    <img
                                        src="/images/landing/client-desktop.svg"
                                        width="420"
                                        height="318"
                                        alt="Карточка клиента с визитами и заметкой"
                                        loading="lazy"
                                    />
                                </figure>
                                <figure>
                                    <figcaption>Услуга / цена и длительность</figcaption>
                                    <img
                                        src="/images/landing/service-editor-desktop.webp"
                                        width="458"
                                        height="315"
                                        alt="Редактор услуги ИРСИ"
                                        loading="lazy"
                                    />
                                </figure>
                            </div>
                            <div
                                className="carousel client-mobile"
                                role="region"
                                aria-roledescription="карусель"
                                aria-label="Клиенты и услуги на телефоне"
                                ref={clientCarouselRef}
                            >
                                <div className="carousel-head">
                                    <span className="carousel-count" aria-live="polite" aria-atomic="true">
                                        1 / 3
                                    </span>
                                    <span className="carousel-hint">Листайте экраны</span>
                                    <div className="carousel-controls">
                                        <button type="button" data-slide="prev" aria-label="Предыдущий экран" disabled>
                                            ‹
                                        </button>
                                        <button type="button" data-slide="next" aria-label="Следующий экран">
                                            ›
                                        </button>
                                    </div>
                                </div>
                                <div
                                    className="carousel-track"
                                    tabIndex={0}
                                    aria-label="Клиенты и услуги на телефоне. Используйте свайп или клавиши влево и вправо"
                                >
                                    <figure className="slide">
                                        <figcaption>01 / Ваши услуги</figcaption>
                                        <img
                                            src="/images/landing/services-mobile.webp"
                                            width="780"
                                            height="927"
                                            alt="Ваши услуги"
                                            loading="lazy"
                                            draggable={false}
                                        />
                                    </figure>
                                    <figure className="slide">
                                        <figcaption>02 / Цена и длительность</figcaption>
                                        <img
                                            src="/images/landing/service-editor-mobile.webp"
                                            width="780"
                                            height="925"
                                            alt="Цена и длительность"
                                            loading="lazy"
                                            draggable={false}
                                        />
                                    </figure>
                                    <figure className="slide">
                                        <figcaption>03 / Карточка клиента</figcaption>
                                        <img
                                            src="/images/landing/client-mobile.webp"
                                            width="780"
                                            height="1301"
                                            alt="Карточка клиента"
                                            loading="lazy"
                                            draggable={false}
                                        />
                                    </figure>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section className="solo">
                        <div className="wrap grid">
                            <div className="eyebrow">
                                <span>06</span>Для одного мастера
                            </div>
                            <h2>
                                Работать на себя.
                                <br /> <span>Не делать всё вручную.</span>
                            </h2>
                            <p>ИРСИ сосредоточен на Вашем личном расписании. Без салонных ролей, склада и лишних управленческих настроек.</p>
                        </div>
                    </section>

                    <section className="section wrap" id="pro">
                        <div className="pro-grid">
                            <div className="pro-copy">
                                <div className="eyebrow">
                                    <span>07</span>Профи / следующий уровень
                                </div>
                                <h2>
                                    Больше возможностей.
                                    <br /> Когда они нужны.
                                </h2>
                                <p>Старт закрывает базовую работу. Профи добавляет сценарии для повторных визитов и использования расписания.</p>
                            </div>
                            <div>
                                <article className="pro-item">
                                    <span>01</span>
                                    <div>
                                        <h3>«Хочу раньше» + AutoFill</h3>
                                        <p>
                                            Если уже записанный клиент выбрал «Хочу раньше», ИРСИ может предложить освободившееся подходящее
                                            время. Без гарантии заполнения каждого окна.
                                        </p>
                                    </div>
                                </article>
                                <article className="pro-item">
                                    <span>02</span>
                                    <div>
                                        <h3>Серии записей клиентов</h3>
                                        <p>Для повторяющихся визитов клиента.</p>
                                    </div>
                                </article>
                                <article className="pro-item">
                                    <span>03</span>
                                    <div>
                                        <h3>Аналитика каналов</h3>
                                        <p>Чтобы видеть, из каких каналов приходят записи.</p>
                                    </div>
                                </article>
                            </div>
                        </div>
                        <figure className="analytics">
                            <img
                                src="/images/landing/analytics.webp"
                                width="1333"
                                height="817"
                                alt="Реальный интерфейс аналитики ИРСИ"
                                loading="lazy"
                            />
                            <figcaption>
                                Базовая аналитика — в тарифе «Старт», аналитика каналов — в «Профи». Числа на скриншоте показывают интерфейс и
                                не являются обещанием результата.
                            </figcaption>
                        </figure>
                    </section>

                    <section className="section wrap pricing" id="pricing">
                        <div className="pricing-top">
                            <div className="eyebrow">
                                <span>08</span>Прозрачные тарифы
                            </div>
                            <h2>
                                Начните бесплатно.
                                <br /> Без данных карты.
                            </h2>
                            <p>Старт — для ежедневной работы. Профи — для дополнительных сценариев.</p>
                        </div>
                        <div className="prices">
                            <article className="price">
                                <div className="price-head">
                                    <h3>Старт</h3>
                                    <span>Без пробного периода</span>
                                </div>
                                <div className="amount">0 ₽ <span>/ бесплатно</span></div>
                                <p>Полноценная основа для онлайн-записи и Вашего расписания.</p>
                                <ul>
                                    <li>Онлайн-запись без лимита записей</li>
                                    <li>Запись через ссылку, VK и MAX</li>
                                    <li>Календарь и ручные записи</li>
                                    <li>Клиенты, услуги, категории и цены</li>
                                    <li>Рабочие часы, периоды и перерывы</li>
                                    <li>Разовые и повторяющиеся блокировки</li>
                                    <li>Напоминания и подтверждение визита</li>
                                    <li>Базовая аналитика</li>
                                </ul>
                                <a className="btn" href="/auth/login" data-event="pricing_start_click">
                                    Начать бесплатно
                                </a>
                            </article>
                            <article className="price pro">
                                <div className="price-head">
                                    <h3>Профи</h3>
                                    <span>Больше возможностей</span>
                                </div>
                                <div className="amount">490 ₽ <span>/ месяц</span></div>
                                <p>Дополнительные возможности для работы с расписанием.</p>
                                <ul>
                                    <li>Всё из тарифа «Старт»</li>
                                    <li>«Хочу раньше» и AutoFill</li>
                                    <li>Серии записей клиентов</li>
                                    <li>Аналитика каналов</li>
                                </ul>
                                <a className="btn secondary" href="#pro" data-event="pricing_pro_click">
                                    Подробнее о «Профи»
                                </a>
                            </article>
                        </div>
                        <p className="price-note">Для регистрации данные карты не нужны. Перейти на «Профи» можно позже.</p>
                    </section>

                    <section className="section wrap start">
                        <div className="section-heading grid">
                            <div className="eyebrow">
                                <span>09</span>Как начать
                            </div>
                            <h2>
                                Ваши услуги.
                                <br /> Ваш график. Ваша ссылка.
                            </h2>
                        </div>
                        <div className="start-steps">
                            <article className="start-step">
                                <span>01</span>
                                <h3>
                                    Войдите через
                                    <br /> VK или MAX.
                                </h3>
                                <p>Выберите удобный способ регистрации мастера.</p>
                            </article>
                            <article className="start-step">
                                <span>02</span>
                                <h3>
                                    Настройте услуги
                                    <br /> и рабочее время.
                                </h3>
                                <p>Добавьте цены, длительность и свой график.</p>
                            </article>
                            <article className="start-step">
                                <span>03</span>
                                <h3>
                                    Поделитесь
                                    <br /> личной ссылкой.
                                </h3>
                                <p>Клиент сможет выбрать услугу и доступное время.</p>
                            </article>
                        </div>
                    </section>

                    <section className="final">
                        <div className="wrap grid">
                            <div className="eyebrow">
                                <span></span>Следующая запись начинается здесь
                            </div>
                            <h2>
                                Ваше дело.
                                <br /> В Вашем ритме.
                            </h2>
                            <div className="final-action">
                                <a className="btn" href="/auth/login" data-event="cta_final_click">
                                    Начать бесплатно
                                </a>
                                <span className="caption">0 ₽ · без лимита записей · без данных карты</span>
                            </div>
                        </div>
                    </section>
                </main>

                <footer className="footer wrap">
                    <div className="footer-top">
                        <a href="#" aria-label="ИРСИ — в начало">
                            <img className="logo" src="/images/landing/logo-irsi.svg" alt="ИРСИ" width="132" height="35" />
                        </a>
                        <div className="footer-links">
                            <a href="/auth/login">Вход для мастеров</a>
                            <a href="/offer">Оферта</a>
                            <a href="/privacy">Политика и согласия</a>
                        </div>
                    </div>
                    <div className="footer-bottom">
                        <span>© ИРСИ, 2026</span>
                        <span>Ритм. Время. Система.</span>
                        <a href="#main">Наверх</a>
                    </div>
                </footer>
            </div>
        </>
    );
}
