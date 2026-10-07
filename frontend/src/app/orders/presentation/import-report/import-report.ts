import { ChangeDetectionStrategy, Component, input, signal } from '@angular/core';
import { ImportBatchResult, ImportOutcome } from '../../domain/orders.models';

@Component({
  selector: 'app-import-report',
  templateUrl: './import-report.html',
  styleUrl: './import-report.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ImportReport {
  readonly report = input.required<ImportBatchResult>();
  readonly shopId = input.required<string>();
  readonly expanded = signal(true);
  readonly outcomes: { value: ImportOutcome; label: string }[] = [
    { value: 'created', label: 'Создано' },
    { value: 'updated', label: 'Обновлено' },
    { value: 'unchanged', label: 'Без изменений' },
    { value: 'duplicate', label: 'Дубли' },
    { value: 'rejected', label: 'Отклонено' },
  ];
  outcomeLabel(outcome: ImportOutcome): string {
    return {
      created: 'Создан',
      updated: 'Обновлён',
      unchanged: 'Без изменений',
      duplicate: 'Дубль',
      rejected: 'Отклонён',
    }[outcome];
  }

  fieldLabel(field: string): string {
    const labels: Record<string, string> = {
      id: 'Номер заказа',
      status: 'Статус',
      created_at: 'Дата создания',
      'customer.name': 'Имя покупателя',
      'customer.phone': 'Телефон',
      'delivery.type': 'Получение',
      'delivery.district': 'Район',
      'delivery.address': 'Адрес',
      'delivery.date': 'Дата доставки',
      'delivery.time_from': 'Начало интервала',
      'delivery.time_to': 'Конец интервала',
      items: 'Позиции заказа',
      total: 'Сумма заказа',
    };
    if (field.startsWith('items.')) return 'Позиция заказа';
    return labels[field] ?? 'Данные заказа';
  }
}
