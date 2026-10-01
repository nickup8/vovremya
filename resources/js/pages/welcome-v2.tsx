import { useEffect, useState } from 'react';
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
                </main>
            </div>
        </>
    );
}
