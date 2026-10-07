import { DOCUMENT } from '@angular/common';
import { ChangeDetectionStrategy, Component, inject, OnInit } from '@angular/core';
import { OrdersStore } from './orders/application/orders.store';
import { ImportReport } from './orders/presentation/import-report/import-report';
import { OrdersTable } from './orders/presentation/table/orders-table';

@Component({
  selector: 'app-root',
  imports: [OrdersTable, ImportReport],
  templateUrl: './app.html',
  styleUrl: './app.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class App implements OnInit {
  readonly store = inject(OrdersStore);
  private readonly location = inject(DOCUMENT).location;

  ngOnInit(): void {
    const requestedShop = new URLSearchParams(this.location.search).get('shop');
    if (
      requestedShop &&
      requestedShop !== this.store.shopId() &&
      this.store.setShop(requestedShop)
    ) {
      return;
    }
    this.store.refresh();
  }

  skipHref(): string {
    return `${this.location.pathname}${this.location.search}#orders`;
  }
}
