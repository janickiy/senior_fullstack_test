import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { App } from './app';
import {
  emptyPage,
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
      providers: [provideHttpClient(), provideHttpClientTesting()],
    }).compileComponents();

    http = TestBed.inject(HttpTestingController);
    fixture = TestBed.createComponent(App);
    root = fixture.nativeElement as HTMLElement;
    fixture.detectChanges();
  });

  afterEach(() => {
    http.verify();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    vi.useRealTimers();
  });

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

  function startImport(file = new File(['xlsx'], 'orders.xlsx')) {
    chooseExcel(file);
    return http.expectOne({ method: 'POST', url: '/api/shops/1/orders/import/excel' });
  }

  function chooseExcel(file: File): HTMLInputElement {
    const input = root.querySelector<HTMLInputElement>('input[type="file"]')!;
    Object.defineProperty(input, 'files', { configurable: true, value: [file] });
    Object.defineProperty(input, 'value', { configurable: true, writable: true, value: file.name });
    input.dispatchEvent(new Event('change', { bubbles: true }));
    fixture.detectChanges();
    return input;
  }

  it('загружает только заказы текущего магазина и объясняет пустой список', async () => {
    await renderPage();

    expect(text(root.querySelector('h1')!)).toBe('Заказы');
    expect(text()).toContain('Заказов пока нет');
    expect(button('Загрузить заказы').disabled).toBe(false);
    expect(
      Array.from(root.querySelectorAll('button')).some((item) => text(item) === 'Загрузить Excel'),
    ).toBe(false);
    expect(text(root.querySelector('.brand')!)).toBe('Магазин.');
    expect(root.querySelector('#shop-id')).toBeNull();
    expect(
      Array.from(root.querySelectorAll('button')).some((item) => text(item) === 'Открыть'),
    ).toBe(false);
  });

  it('отправляет выбранный Excel-файл и блокирует повторное нажатие', async () => {
    await renderPage();
    const importButton = button('Загрузить заказы');
    const file = new File(['xlsx'], 'orders.xlsx');
    const request = startImport(file);

    expect(request.request.body).toBeInstanceOf(FormData);
    expect((request.request.body.get('file') as File).name).toBe(file.name);
    expect(importButton.disabled).toBe(true);
    expect(text(importButton)).toContain('Загружаем');
    importButton.click();
    http.expectNone((request) => request.method === 'POST');
    http.expectNone((request) => request.method === 'GET');

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
    const file = new File(['xlsx'], 'orders.xlsx');
    startImport(file).flush(
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

    const input = root.querySelector<HTMLInputElement>('input[type="file"]')!;
    const openFile = vi.spyOn(input, 'click');
    button('Повторить загрузку').click();
    expect(openFile).toHaveBeenCalledOnce();
    startImport(file).flush(importReport);
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

  it('загружает Excel как файл, блокирует повтор и отображает номера строк листа в отчёте', async () => {
    await renderPage();
    const input = root.querySelector<HTMLInputElement>('input[type="file"]')!;
    const openFile = vi.spyOn(input, 'click');
    button('Загрузить заказы').click();
    expect(openFile).toHaveBeenCalledOnce();
    expect(input.accept).toContain('.xlsx');

    const file = new File(['xlsx'], 'orders.xlsx');
    chooseExcel(file);
    expect(input.value).toBe('');
    expect(button('Загружаем…').disabled).toBe(true);
    expect(button('Выгрузить заказы').disabled).toBe(true);
    chooseExcel(file);
    const request = http.expectOne('/api/shops/1/orders/import/excel');
    expect(request.request.method).toBe('POST');
    const uploaded = request.request.body.get('file') as File;
    expect(uploaded.name).toBe(file.name);
    expect(uploaded.size).toBe(file.size);
    request.flush({
      ...importReport,
      results: importReport.results.map((result) => ({ ...result, source_row: result.index + 2 })),
    });
    await renderPage(ordersPage);
    expect(text(root.querySelector('.import-results')!)).toContain('orders.xlsx');
    expect(text(root.querySelector('.result-id')!)).toContain('Строка 2');
    expect(button('Загрузить заказы').disabled).toBe(false);
    expect(text()).toContain('Анна Лебедева');

    chooseExcel(file);
    http.expectOne('/api/shops/1/orders/import/excel').flush(importReport);
    await renderPage(ordersPage);
  });

  it('отклоняет неподдерживаемый или слишком большой файл и для повтора открывает выбор Excel', async () => {
    await renderPage();
    chooseExcel(new File(['content'], 'orders.csv'));
    expect(text(root.querySelector('.import-error')!)).toContain('расширением .xlsx');
    http.expectNone('/api/shops/1/orders/import/excel');
    const input = root.querySelector<HTMLInputElement>('input[type="file"]')!;
    const openFile = vi.spyOn(input, 'click');
    button('Повторить загрузку').click();
    expect(openFile).toHaveBeenCalledOnce();
    http.expectNone((request) => request.method === 'POST');

    const largeFile = new File(['content'], 'orders.xlsx');
    Object.defineProperty(largeFile, 'size', { value: 10 * 1024 * 1024 + 1 });
    chooseExcel(largeFile);
    expect(text(root.querySelector('.import-error')!)).toContain('10 МБ');
    http.expectNone('/api/shops/1/orders/import/excel');
  });

  it('скачивает все заказы магазина в Excel при выбранном фильтре и страницу не меняет', async () => {
    await renderPage(ordersPage);
    button('Новые').click();
    await renderPage(
      { ...ordersPage, pagination: { page: 1, limit: 10, total: 11, pages: 2 } },
      '1',
      1,
      'new',
    );
    button('Следующая страница').click();
    await renderPage(
      { ...ordersPage, pagination: { page: 2, limit: 10, total: 11, pages: 2 } },
      '1',
      2,
      'new',
    );
    vi.useFakeTimers();
    const create = vi.fn().mockReturnValue('blob:all-orders');
    const revoke = vi.fn();
    vi.stubGlobal('URL', { createObjectURL: create, revokeObjectURL: revoke });
    let filename = '';
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (
      this: HTMLAnchorElement,
    ) {
      filename = this.download;
    });

    button('Выгрузить заказы').click();
    fixture.detectChanges();
    expect(button('Выгружаем…').disabled).toBe(true);
    const request = http.expectOne('/api/shops/1/orders/export/excel');
    expect(request.request.params.keys()).toEqual([]);
    expect(request.request.responseType).toBe('blob');
    request.flush(new Blob(['xlsx']));
    fixture.detectChanges();
    expect(filename).toBe('orders-shop-1.xlsx');
    expect(button('Выгрузить заказы').disabled).toBe(false);
    expect(button('Новые').getAttribute('aria-pressed')).toBe('true');
    expect(fixture.componentInstance.store.page()).toBe(2);
    expect(revoke).not.toHaveBeenCalled();
    vi.advanceTimersByTime(1000);
    expect(revoke).toHaveBeenCalledWith('blob:all-orders');
    http.expectNone((request) => request.url === '/api/shops/1/orders');
  });

  it('показывает ошибку скачивания и позволяет скачать именованный шаблон после повторного нажатия', async () => {
    await renderPage();
    button('Шаблон Excel').click();
    http.expectOne('/api/shops/1/orders/template/excel').error(new ProgressEvent('error'));
    fixture.detectChanges();
    expect(text(root.querySelector('.download-error')!)).toContain(
      'Не удалось скачать шаблон Excel',
    );
    expect(button('Шаблон Excel').disabled).toBe(false);

    vi.useFakeTimers();
    vi.stubGlobal('URL', {
      createObjectURL: vi.fn().mockReturnValue('blob:template'),
      revokeObjectURL: vi.fn(),
    });
    let filename = '';
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (
      this: HTMLAnchorElement,
    ) {
      filename = this.download;
    });
    button('Шаблон Excel').click();
    fixture.detectChanges();
    expect(button('Готовим шаблон…').disabled).toBe(true);
    http.expectOne('/api/shops/1/orders/template/excel').flush(new Blob(['xlsx']));
    fixture.detectChanges();
    expect(filename).toBe('orders-template.xlsx');
    expect(root.querySelector('.download-error')).toBeNull();
    vi.runAllTimers();
  });
});
