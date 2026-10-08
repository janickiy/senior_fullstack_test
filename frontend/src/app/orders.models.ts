export type OrderStatus = 'new' | 'accepted' | 'delivering' | 'delivered' | 'cancelled';

export type ImportOutcome = 'created' | 'updated' | 'unchanged' | 'duplicate' | 'rejected';

export interface OrderItem {
  sku: string;
  name: string;
  qty: number;
  price: number;
}

export interface OrderView {
  id: number;
  marketplace_id: string;
  status: OrderStatus;
  created_at: string;
  customer: { name: string; phone: string };
  delivery: {
    type: 'delivery' | 'pickup';
    district: string | null;
    address: string | null;
    starts_at: string | null;
    ends_at: string | null;
  };
  items: OrderItem[];
  items_total: number;
  marketplace_total: number;
  delivery_cost: number;
  total: number;
  needs_review: boolean;
}

export interface OrderPagination {
  page: number;
  limit: number;
  total: number;
  pages: number;
}

export interface OrderPage {
  items: OrderView[];
  pagination: OrderPagination;
}

export interface ImportWarning {
  code: string;
  message: string;
}

export interface ImportValidationError {
  field: string;
  message: string;
}

export interface ImportOrderResult {
  index: number;
  source_row?: number;
  marketplace_id: string | null;
  outcome: ImportOutcome;
  code: string;
  message: string;
  order_id: number | null;
  status: OrderStatus | null;
  needs_review: boolean | null;
  items_total: number | null;
  delivery_cost: number | null;
  total: number | null;
  warnings: ImportWarning[];
  errors: ImportValidationError[];
}

export interface ImportSummary {
  total: number;
  created: number;
  updated: number;
  unchanged: number;
  duplicate: number;
  rejected: number;
}

export interface ImportBatchResult {
  summary: ImportSummary;
  results: ImportOrderResult[];
}
