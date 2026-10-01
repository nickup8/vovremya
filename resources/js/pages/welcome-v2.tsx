import { useEffect, useRef, useState } from 'react';
import { Head } from '@inertiajs/react';
import '../../css/welcome-v2.css';

/* IRSI landing v2 — step 1: header, navigation, hero, hero calendar visual.
   Step 3: section 02 (booking flow carousel).
   Ported from docs/landing/irsi-landing-2026-09-30-v3.html. */

const bookingSlides = [
    { src: '/images/landing/booking-service.webp', alt: 'Выбор услуги', caption: '01 / Выбор услуги', width: 443, height: 485 },
    { src: '/images/landing/booking-date.webp', alt: 'Выбор даты', caption: '02 / Выбор даты', width: 442, height: 473 },
    { src: '/images/landing/booking-time.webp', alt: 'Выбор времени', caption: '03 / Выбор времени', width: 442, height: 473 },
    {
        src: '/images/landing/booking-confirmation.webp',
        alt: 'Подтверждение записи',
        caption: '04 / Подтверждение записи',
        width: 444,
        height: 368,
    },
];

export default function WelcomeV2() {
    const [menuOpen, setMenuOpen] = useState(false);
    const [slideIndex, setSlideIndex] = useState(0);
    const galleryRef = useRef<HTMLDivElement>(null);
    const trackRef = useRef<HTMLDivElement>(null);

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

    useEffect(() => {
        const root = galleryRef.current;
        const track = trackRef.current;
        if (!root || !track) return;

        const slides = Array.from(track.querySelectorAll<HTMLElement>('.slide'));
        const prev = root.querySelector<HTMLButtonElement>('[data-slide="prev"]');
        const next = root.querySelector<HTMLButtonElement>('[data-slide="next"]');
        const reduced = matchMedia('(prefers-reduced-motion: reduce)');

        let current = 0;
        let target = 0;
        let frame = 0;
        let settle = 0;
        let lastWidth = 0;
        let drag: { id: number; x: number; left: number } | null = null;

        const position = (index: number) => slides[index].offsetLeft - slides[0].offsetLeft;
        const show = (index: number) => {
            current = index;
            setSlideIndex(index);
        };
        const nearest = () =>
            slides.reduce(
                (best, slide, index) =>
                    Math.abs(position(index) - track.scrollLeft) < Math.abs(position(best) - track.scrollLeft) ? index : best,
                0,
            );
        const go = (index: number, smooth = true) => {
            target = Math.max(0, Math.min(slides.length - 1, index));
            show(target);
            track.scrollTo({ left: position(target), behavior: smooth && !reduced.matches ? 'smooth' : 'auto' });
        };

        const onPrev = () => go(target - 1);
        const onNext = () => go(target + 1);
        const onKeydown = (event: KeyboardEvent) => {
            const actions: Record<string, number> = {
                ArrowLeft: target - 1,
                ArrowRight: target + 1,
                Home: 0,
                End: slides.length - 1,
            };
            if (Object.hasOwn(actions, event.key)) {
                event.preventDefault();
                go(actions[event.key]);
            }
        };
        const onScroll = () => {
            cancelAnimationFrame(frame);
            frame = requestAnimationFrame(() => {
                if (drag) show(nearest());
            });
            clearTimeout(settle);
            settle = window.setTimeout(() => {
                target = nearest();
                show(target);
            }, 160);
        };
        const onPointerDown = (event: PointerEvent) => {
            if (event.pointerType !== 'mouse' || event.button !== 0) return;
            clearTimeout(settle);
            drag = { id: event.pointerId, x: event.clientX, left: track.scrollLeft };
            track.classList.add('is-dragging');
            track.setPointerCapture(event.pointerId);
        };
        const onPointerMove = (event: PointerEvent) => {
            if (!drag || drag.id !== event.pointerId) return;
            track.scrollLeft = drag.left + drag.x - event.clientX;
        };
        const finish = (event: PointerEvent) => {
            if (!drag || drag.id !== event.pointerId) return;
            drag = null;
            track.classList.remove('is-dragging');
            if (track.hasPointerCapture(event.pointerId)) track.releasePointerCapture(event.pointerId);
            go(nearest());
        };
        const onResize = () => {
            const width = track.clientWidth;
            if (!width || Math.abs(width - lastWidth) < 1) return;
            lastWidth = width;
            go(current, false);
        };

        prev?.addEventListener('click', onPrev);
        next?.addEventListener('click', onNext);
        track.addEventListener('keydown', onKeydown);
        track.addEventListener('scroll', onScroll, { passive: true });
        track.addEventListener('pointerdown', onPointerDown);
        track.addEventListener('pointermove', onPointerMove);
        track.addEventListener('pointerup', finish);
        track.addEventListener('pointercancel', finish);
        const observer = new ResizeObserver(onResize);
        observer.observe(track);
        show(0);

        return () => {
            cancelAnimationFrame(frame);
            clearTimeout(settle);
            prev?.removeEventListener('click', onPrev);
            next?.removeEventListener('click', onNext);
            track.removeEventListener('keydown', onKeydown);
            track.removeEventListener('scroll', onScroll);
            track.removeEventListener('pointerdown', onPointerDown);
            track.removeEventListener('pointermove', onPointerMove);
            track.removeEventListener('pointerup', finish);
            track.removeEventListener('pointercancel', finish);
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
                            <div
                                className="carousel booking-gallery"
                                role="region"
                                aria-roledescription="карусель"
                                aria-label="Как клиент записывается"
                                ref={galleryRef}
                            >
                                <div className="booking-slider-top">
                                    <div>
                                        <span className="booking-step">
                                            Шаг{' '}
                                            <span className="carousel-count" aria-live="polite" aria-atomic="true">
                                                {slideIndex + 1} из {bookingSlides.length}
                                            </span>
                                        </span>
                                        <h3 className="booking-slide-title">{bookingSlides[slideIndex].alt}</h3>
                                    </div>
                                    <div className="carousel-controls">
                                        <button
                                            type="button"
                                            aria-controls="booking-slides"
                                            data-slide="prev"
                                            aria-label="Предыдущий шаг"
                                            disabled={slideIndex === 0}
                                        >
                                            ‹
                                        </button>
                                        <button
                                            type="button"
                                            aria-controls="booking-slides"
                                            data-slide="next"
                                            aria-label="Следующий шаг"
                                            disabled={slideIndex === bookingSlides.length - 1}
                                        >
                                            ›
                                        </button>
                                    </div>
                                </div>
                                <div
                                    className="carousel-track"
                                    tabIndex={0}
                                    id="booking-slides"
                                    ref={trackRef}
                                    aria-label="Как клиент записывается. Используйте свайп или клавиши влево и вправо"
                                >
                                    {bookingSlides.map((slide) => (
                                        <figure className="slide" key={slide.src}>
                                            <figcaption>{slide.caption}</figcaption>
                                            <img
                                                src={slide.src}
                                                width={slide.width}
                                                height={slide.height}
                                                alt={slide.alt}
                                                loading="lazy"
                                                draggable={false}
                                            />
                                        </figure>
                                    ))}
                                </div>
                                <div className="booking-progress" aria-hidden="true">
                                    {bookingSlides.map((slide, index) => (
                                        <span key={slide.src} className={index === slideIndex ? 'is-active' : undefined} />
                                    ))}
                                </div>
                            </div>
                        </div>
                    </section>
                </main>
            </div>
        </>
    );
}
