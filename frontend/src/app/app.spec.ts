import { provideOrdersApi } from './orders/infrastructure/orders-api';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { App } from './app';
import {
  emptyPage,
  fixturePayload,
  importReport,
  ordersPage,
  repeatedImportReport,
  savedOrder,
} from './test-fixtures';

describe('Интерфейс заказов', () => {
  let fixture: ComponentFixture<App>;
  let http: HttpTestingController;
  let root: HTMLElement;

  beforeEach(async () => {
    window.history.replaceState(null, '', '/');
    await TestBed.configureTestingModule({
      imports: [App],
      providers: [provideHttpClient(), provideHttpClientTesting(), provideOrdersApi()],
    }).compileComponents();

    http = TestBed.inject(HttpTestingController);
    fixture = TestBed.createComponent(App);
    root = fixture.nativeElement as HTMLElement;
    fixture.detectChanges();
  });

  afterEach(() => http.verify());

  function text(element: Element = root): string {
    return element.textContent?.replace(/\s+/g, ' ').trim() ?? '';
  }

  function button(label: string | RegExp): HTMLButtonElement {
    const found = Array.from(root.querySelectorAll('button')).find((item) => {
      const name = item.getAttribute('aria-label') || text(item);
      return typeof label === 'string' ? name === label : label.test(name);
    });
    if (!found) {
      throw new Error(`Не найдена кнопка ${label}`);
    }
    return found;
  }

  function listRequest(shop = '1', page = 1, status = '') {
    return http.expectOne(
      (request) => {
        return (
          request.method === 'GET' &&
          request.url === `/api/shops/${shop}/orders` &&
          request.params.get('page') === String(page) &&
          request.params.get('limit') === '10' &&
          (request.params.get('status') ?? '') === status
        );
      },
      `Список магазина ${shop}, страница ${page}, статус ${status || 'любой'}`,
    );
  }

  async function renderPage(body: object = emptyPage, shop = '1', page = 1, status = '') {
    listRequest(shop, page, status).flush(body);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  function startImport() {
    button('Загрузить заказы').click();
    fixture.detectChanges();
    const asset = http.expectOne({ method: 'GET', url: '/assets/marketplace-orders.json' });
    asset.flush(fixturePayload);
    fixture.detectChanges();
    return http.expectOne({ method: 'POST', url: '/api/shops/1/orders/import' });
  }

  it('загружает только заказы текущего магазина и объясняет пустой список', async () => {
    await renderPage();

    expect(text(root.querySelector('h1')!)).toBe('Заказы');
    expect(text()).toContain('Заказов пока нет');
    expect(button('Загрузить заказы').disabled).toBe(false);
    expect(text(root.querySelector('.brand')!)).toBe('Магазин.');
    expect(root.querySelector('#shop-id')).toBeNull();
    expect(
      Array.from(root.querySelectorAll('button')).some((item) => text(item) === 'Открыть'),
    ).toBe(false);
  });

  it('отправляет содержимое ассета без изменений и блокирует повторное нажатие', async () => {
    await renderPage();
    const importButton = button('Загрузить заказы');
    const request = startImport();

    expect(request.request.body).toEqual(fixturePayload);
    expect(importButton.disabled).toBe(true);
    expect(text(importButton)).toContain('Загружаем');
    importButton.click();
    http.expectNone({ method: 'POST', url: '/api/shops/1/orders/import' });
    http.expectNone({ method: 'GET', url: '/assets/marketplace-orders.json' });

    request.flush(importReport);
    await renderPage(ordersPage);
    expect(button('Загрузить заказы').disabled).toBe(false);
    expect(text()).toContain('Анна Лебедева');
  });

  it('показывает исход для каждого заказа, причины отказов и предупреждение о сумме', async () => {
    await renderPage();
    startImport().flush(importReport);
    await renderPage(ordersPage);

    const content = text();
    for (const label of ['Создано', 'Обновлено', 'Без изменений', 'Дубли', 'Отклонено']) {
      expect(content).toContain(label);
    }
    expect(text(root.querySelector('.summary-created .summary-number')!)).toBe('7');
    expect(text(root.querySelector('.summary-rejected .summary-number')!)).toBe('3');
    expect(text(root.querySelector('.summary-duplicate .summary-number')!)).toBe('1');
    expect(root.querySelectorAll('#import-details > li')).toHaveLength(11);
    expect(text(root.querySelector('.outcome-created')!)).toBe('Создан');
    expect(text(root.querySelector('.outcome-rejected')!)).toBe('Отклонён');
    expect(text(root.querySelector('.outcome-duplicate')!)).toBe('Дубль');
    for (const id of [
      'MP-1001',
      'MP-1002',
      'MP-1003',
      'MP-1004',
      'MP-1005',
      'MP-1006',
      'MP-1007',
      'MP-1008',
      'MP-1009',
      'MP-1010',
    ]) {
      expect(content).toContain(id);
    }
    expect(content).toContain('Для района zarechye не задан тариф доставки.');
    expect(content).toContain('Некорректный телефон покупателя.');
    expect(content).toContain('Неизвестный статус PACKING.');
    expect(content).toContain('Повтор идентификатора в пачке');
    expect(content).toMatch(/[Нн]ужна проверка/);
    expect(content).toContain('Сумма позиций отличается');
  });

  it('показывает заказы в таблице с заголовками и основными данными', async () => {
    await renderPage(ordersPage);

    const table = root.querySelector<HTMLTableElement>('table.orders-table')!;
    expect(table).not.toBeNull();
    expect(table.getAttribute('aria-label')).toBe('Список заказов');
    expect(Array.from(table.querySelectorAll('thead th')).map((header) => text(header))).toEqual([
      'Заказ',
      'Получатель',
      'Доставка',
      'К оплате',
      'Статус',
      'Детали',
    ]);
    expect(table.querySelectorAll('tbody tr.order-row')).toHaveLength(2);

    const rows = table.querySelectorAll('tbody tr.order-row');
    expect(text(rows[0])).toContain('MP-1001');
    expect(text(rows[0])).toContain('Анна Лебедева');
    expect(text(rows[0])).toContain('+79001234567');
    expect(text(rows[0])).toMatch(/3\s*500(?:,00)?\s*₽/);
    expect(text(rows[1])).toMatch(/4\s*300(?:,00)?\s*₽/);
    expect(text(rows[1])).toContain('Нужна проверка');
    expect(table.querySelector('tr.order-detail-row')).toBeNull();
  });

  it('кнопка раскрывает адрес, позиции и расчёт заказа и позволяет закрыть детали', async () => {
    await renderPage(ordersPage);

    const toggle = button('Показать детали заказа MP-1001');
    expect(toggle.getAttribute('aria-expanded')).toBe('false');
    expect(toggle.getAttribute('aria-controls')).toBe('order-details-1');
    toggle.click();
    fixture.detectChanges();

    const details = root.querySelector<HTMLElement>('#order-details-1')!;
    expect(details).not.toBeNull();
    expect(details.closest('tr')?.classList.contains('order-detail-row')).toBe(true);
    expect(details.closest('tr')?.querySelector('td')?.getAttribute('colspan')).toBe('6');
    expect(text(details)).toContain('ул. Большая Садовая, 10, кв. 4');
    expect(text(details)).toContain('Букет «Нежность»');
    expect(text(details)).toMatch(/300(?:,00)?\s*₽/);
    expect(text(details)).toMatch(/3\s*200(?:,00)?\s*₽/);
    expect(text(details)).toMatch(/3\s*500(?:,00)?\s*₽/);
    expect(button('Скрыть детали заказа MP-1001').getAttribute('aria-expanded')).toBe('true');

    button('Скрыть детали заказа MP-1001').click();
    fixture.detectChanges();
    expect(root.querySelector('#order-details-1')).toBeNull();
    expect(button('Показать детали заказа MP-1001').getAttribute('aria-expanded')).toBe('false');

    button('Показать детали заказа MP-1009').click();
    fixture.detectChanges();
    const reviewDetails = root.querySelector<HTMLElement>('#order-details-9')!;
    expect(text(reviewDetails)).toMatch(/700(?:,00)?\s*₽/);
    expect(text(reviewDetails)).toMatch(/4\s*300(?:,00)?\s*₽/);
    expect(text(reviewDetails)).toMatch(/3\s*600(?:,00)?\s*₽/);
    expect(text(reviewDetails)).toMatch(/3\s*000(?:,00)?\s*₽/);
  });

  it('объясняет обновлённый, неизменённый и сохранённый финальный статус при повторном импорте', async () => {
    await renderPage(ordersPage);
    startImport().flush(repeatedImportReport);
    await renderPage(ordersPage);

    expect(text(root.querySelector('.outcome-updated')!)).toBe('Обновлён');
    expect(text(root.querySelector('.outcome-unchanged')!)).toBe('Без изменений');
    expect(text(root.querySelector('.summary-updated .summary-number')!)).toBe('1');
    expect(text(root.querySelector('.summary-unchanged .summary-number')!)).toBe('2');
    expect(text()).toContain('Обновлён только статус заказа.');
    expect(text()).toContain('Статус не изменился; остальные данные сохранены без изменений.');
    expect(text()).toContain('Финальный статус сохранён; изменение отклонено.');
    expect(text()).toContain('Повтор идентификатора в пачке');
  });

  it('кнопки статусов запрашивают у API выбранный фильтр и сбрасывают страницу', async () => {
    await renderPage({
      ...ordersPage,
      pagination: { page: 1, limit: 10, total: 11, pages: 2 },
    });
    expect(button('Все заказы').getAttribute('aria-pressed')).toBe('true');
    const statusButtons = root.querySelector('[role="group"][aria-label="Статус заказа"]')!;
    expect(Array.from(statusButtons.querySelectorAll('button')).map((item) => text(item))).toEqual([
      'Все заказы',
      'Новые',
      'Принятые',
      'В доставке',
      'Доставленные',
      'Отменённые',
    ]);

    button('Следующая страница').click();
    await renderPage(
      { ...ordersPage, pagination: { page: 2, limit: 10, total: 11, pages: 2 } },
      '1',
      2,
    );

    button('Принятые').click();
    fixture.detectChanges();

    await renderPage(
      {
        items: [{ ...savedOrder, marketplace_id: 'MP-1002', status: 'accepted' }],
        pagination: { page: 1, limit: 10, total: 1, pages: 1 },
      },
      '1',
      1,
      'accepted',
    );

    expect(text()).toContain('MP-1002');
    expect(text()).not.toContain('MP-1009');
    expect(button('Принятые').getAttribute('aria-pressed')).toBe('true');
    expect(button('Все заказы').getAttribute('aria-pressed')).toBe('false');
    button('Принятые').click();
    http.expectNone((request) => request.method === 'GET');

    button('Все заказы').click();
    await renderPage(ordersPage);
    expect(button('Все заказы').getAttribute('aria-pressed')).toBe('true');
    expect(text()).toContain('MP-1009');
  });

  it('обновляет текущую страницу с выбранным статусом и сохраняет выделенный фильтр', async () => {
    await renderPage(ordersPage);
    button('Новые').click();
    await renderPage(
      { ...ordersPage, pagination: { page: 1, limit: 10, total: 11, pages: 2 } },
      '1',
      1,
      'new',
    );
    button('Следующая страница').click();
    const secondPage = {
      items: [{ ...savedOrder, id: 11, marketplace_id: 'MP-PAGE-2-1' }],
      pagination: { page: 2, limit: 10, total: 11, pages: 2 },
    };
    await renderPage(secondPage, '1', 2, 'new');

    button('Обновить').click();
    fixture.detectChanges();
    expect(button('Обновить').disabled).toBe(true);
    await renderPage(secondPage, '1', 2, 'new');

    expect(button('Обновить').disabled).toBe(false);
    expect(button('Новые').getAttribute('aria-pressed')).toBe('true');
    expect(text()).toContain('MP-PAGE-2-1');
    expect(button('Следующая страница').disabled).toBe(true);
  });

  it('после ошибки импорта показывает её и позволяет повторить попытку', async () => {
    await renderPage();
    startImport().flush(
      { detail: 'Импорт временно недоступен.' },
      {
        status: 503,
        statusText: 'Service Unavailable',
      },
    );
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    expect(text(root.querySelector('[role="alert"]')!)).toContain(
      'Не удалось получить результат импорта.',
    );
    expect(button('Загрузить заказы').disabled).toBe(false);
    http.expectNone((request) => request.method === 'GET' && request.url === '/api/shops/1/orders');

    startImport().flush(importReport);
    await renderPage(ordersPage);
    expect(text()).toContain('MP-1001');
  });

  it('после ошибки списка позволяет повторить запрос и увидеть заказы', async () => {
    listRequest().flush(null, { status: 503, statusText: 'Service Unavailable' });
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    expect(text(root.querySelector('[role="alert"]')!)).toContain('Не удалось получить список');
    button('Повторить').click();
    await renderPage(ordersPage);
    expect(root.querySelector('[role="alert"]')).toBeNull();
    expect(text()).toContain('Анна Лебедева');
  });

  it('переключает страницы на сервере и отключает выход за границы списка', async () => {
    const firstPage = {
      items: Array.from({ length: 10 }, (_, index) => ({
        ...savedOrder,
        id: index + 1,
        marketplace_id: `MP-PAGE-1-${index + 1}`,
      })),
      pagination: { page: 1, limit: 10, total: 11, pages: 2 },
    };
    await renderPage(firstPage);
    expect(button('Предыдущая страница').disabled).toBe(true);
    expect(button('Следующая страница').disabled).toBe(false);

    button('Следующая страница').click();
    await renderPage(
      {
        items: [{ ...savedOrder, id: 11, marketplace_id: 'MP-PAGE-2-1' }],
        pagination: { page: 2, limit: 10, total: 11, pages: 2 },
      },
      '1',
      2,
    );
    expect(text()).toContain('MP-PAGE-2-1');
    expect(text()).not.toContain('MP-PAGE-1-1');
    expect(button('Следующая страница').disabled).toBe(true);
    expect(button('Предыдущая страница').disabled).toBe(false);

    button('Предыдущая страница').click();
    await renderPage(firstPage);
    expect(text()).toContain('MP-PAGE-1-1');
    expect(text()).not.toContain('MP-PAGE-2-1');
  });

  it('смена контекста магазина убирает предыдущие заказы, результат импорта и раскрытые детали', async () => {
    await renderPage();
    startImport().flush(importReport);
    await renderPage(ordersPage);
    button('Показать детали заказа MP-1001').click();
    fixture.detectChanges();
    expect(root.querySelector('#order-details-1')).not.toBeNull();

    fixture.componentInstance.store.setShop('another-shop');
    fixture.detectChanges();

    expect(text()).not.toContain('MP-1009');
    expect(text()).not.toContain('Для района zarechye не задан тариф доставки.');
    await renderPage(
      {
        items: [{ ...savedOrder, marketplace_id: 'OTHER-SHOP-1' }],
        pagination: { page: 1, limit: 10, total: 1, pages: 1 },
      },
      'another-shop',
    );
    expect(text()).toContain('OTHER-SHOP-1');
    expect(root.querySelector('#order-details-1')).toBeNull();
    expect(button('Показать детали заказа OTHER-SHOP-1').getAttribute('aria-expanded')).toBe(
      'false',
    );
    expect(fixture.componentInstance.store.shopId()).toBe('another-shop');
  });
});
