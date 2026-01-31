# Схема данных (DBML)

Ниже представлена схема базы данных проекта в нотации DBML.

```dbml
// Пользователи и Аутентификация
Table users {
  id bigint [primary key]
  name varchar
  surname varchar
  patronymic varchar
  email varchar [unique]
  password varchar
  phone varchar
  country varchar
  city varchar
  is_admin boolean
  region_id bigint
  currency_id bigint
  created_at timestamp
  updated_at timestamp
}

// Финансы
Table currencies {
  id bigint [primary key]
  name varchar
  code varchar
  symbol varchar
  exchange_rate decimal
  created_at timestamp
  updated_at timestamp
}

Table user_balances {
  id bigint [primary key]
  user_id bigint
  currency_id bigint
  balance decimal
  overdue_debt decimal
  created_at timestamp
  updated_at timestamp
}

// Компании и Адреса
Table companies {
  id bigint [primary key]
  user_id bigint
  name varchar
  tax_id varchar
  email varchar
  phone varchar
  created_at timestamp
  updated_at timestamp
  deleted_at timestamp
}

Table company_bank_accounts {
  id bigint [primary key]
  company_id bigint
  name varchar
  bank_name varchar
  account_number varchar
  bic varchar
  created_at timestamp
  updated_at timestamp
}

Table delivery_addresses {
  id bigint [primary key]
  user_id bigint
  name varchar
  address text
  created_at timestamp
  updated_at timestamp
}

// Магазин: Продукты, Категории и Бренды
Table products {
  id bigint [primary key]
  name varchar
  slug varchar [unique]
  base_price decimal
  brand_id bigint
  model_id bigint
  size_chart_id bigint
  description text
  is_new boolean
  is_bestseller boolean
  created_at timestamp
  updated_at timestamp
}

Table categories {
  id bigint [primary key]
  name varchar
  parent_id bigint
  external_id varchar
  description text
  created_at timestamp
  updated_at timestamp
}

Table brands {
  id bigint [primary key]
  name varchar
  slug varchar [unique]
  parent_id bigint
  created_at timestamp
  updated_at timestamp
}

Table product_models {
  id bigint [primary key]
  name varchar
  brand_id bigint
  created_at timestamp
  updated_at timestamp
}

Table category_product {
  category_id bigint
  product_id bigint
}

// Склады и Логистика
Table warehouses {
  id bigint [primary key]
  name varchar
  external_id varchar [unique]
  created_at timestamp
  updated_at timestamp
}

Table regions {
  id bigint [primary key]
  name varchar
  external_id varchar
  created_at timestamp
  updated_at timestamp
}

Table product_warehouse {
  product_id bigint
  warehouse_id bigint
  quantity int
}

Table region_warehouse {
  region_id bigint
  warehouse_id bigint
}

// Параметры и Характеристики
Table attributes {
  id bigint [primary key]
  name varchar
  created_at timestamp
  updated_at timestamp
}

Table attribute_values {
  id bigint [primary key]
  attribute_id bigint
  value varchar
  sort_order int
  created_at timestamp
  updated_at timestamp
}

Table product_attribute_values {
  id bigint [primary key]
  product_id bigint
  attribute_id bigint
  attribute_value_id bigint
  text_value text
  number_value decimal
  boolean_value boolean
}

Table size_charts {
  id bigint [primary key]
  name varchar
  external_id varchar
  created_at timestamp
  updated_at timestamp
}

Table brand_size_chart {
  brand_id bigint
  size_chart_id bigint
}

Table product_barcodes {
  id bigint [primary key]
  product_id bigint
  barcode varchar
  created_at timestamp
  updated_at timestamp
}

Table certificates {
  id bigint [primary key]
  name varchar
  external_id varchar
  created_at timestamp
  updated_at timestamp
}

Table product_certificate {
  product_id bigint
  certificate_id bigint
}

Table segments {
  id bigint [primary key]
  name varchar
  external_id varchar
  created_at timestamp
  updated_at timestamp
}

Table product_segment {
  product_id bigint
  segment_id bigint
}

// Заказы, Корзина и Возвраты
Table orders {
  id bigint [primary key]
  uuid uuid [unique]
  user_id bigint
  company_id bigint
  delivery_address_id bigint
  cart_id bigint
  status varchar
  total_amount decimal
  created_at timestamp
  updated_at timestamp
  deleted_at timestamp
}

Table order_items {
  id bigint [primary key]
  order_id bigint
  product_id bigint
  name varchar
  price decimal
  quantity int
  subtotal decimal
}

Table carts {
  id bigint [primary key]
  user_id bigint
  name varchar
  created_at timestamp
  updated_at timestamp
}

Table cart_items {
  id bigint [primary key]
  cart_id bigint
  product_id bigint
  quantity int
  created_at timestamp
  updated_at timestamp
}

Table returns {
  id bigint [primary key]
  user_id bigint
  status varchar
  total_amount decimal
  created_at timestamp
  updated_at timestamp
}

Table return_items {
  id bigint [primary key]
  return_id bigint
  product_id bigint
  quantity int
  price decimal
  created_at timestamp
  updated_at timestamp
}

Table wishlist_items {
  id bigint [primary key]
  user_id bigint
  product_id bigint
  created_at timestamp
  updated_at timestamp
}

Table favorites {
  id bigint [primary key]
  user_id bigint
  product_id bigint
  created_at timestamp
  updated_at timestamp
}

// Маркетинг и Скидки
Table promotions {
  id bigint [primary key]
  title varchar
  slug varchar [unique]
  description text
  is_active boolean
  starts_at timestamp
  ends_at timestamp
  created_at timestamp
  updated_at timestamp
}

Table product_promotion {
  product_id bigint
  promotion_id bigint
}

Table discounts {
  id bigint [primary key]
  name varchar
  percentage decimal
  starts_at timestamp
  ends_at timestamp
  is_active boolean
  created_at timestamp
  updated_at timestamp
}

Table discount_product {
  discount_id bigint
  product_id bigint
}

Table discount_user {
  discount_id bigint
  user_id bigint
}

Table product_selections {
  id bigint [primary key]
  name varchar
  is_active boolean
  created_at timestamp
  updated_at timestamp
}

Table product_product_selection {
  product_id bigint
  product_selection_id bigint
}

// Контент
Table articles {
  id bigint [primary key]
  title varchar
  slug varchar [unique]
  short_description text
  detailed_description text
  published boolean
  created_at timestamp
  updated_at timestamp
}

Table news {
  id bigint [primary key]
  title varchar
  slug varchar [unique]
  detailed_description text
  created_at timestamp
  updated_at timestamp
}

Table faqs {
  id bigint [primary key]
  title varchar
  content text
  created_at timestamp
  updated_at timestamp
}

Table banners {
  id bigint [primary key]
  title varchar
  linkable_type varchar
  linkable_id bigint
  is_active boolean
  sort_order int
  created_at timestamp
  updated_at timestamp
}

Table stories {
  id bigint [primary key]
  title varchar
  is_active boolean
  created_at timestamp
  updated_at timestamp
}

Table story_slides {
  id bigint [primary key]
  story_id bigint
  title varchar
  content text
  image_url varchar
  sort_order int
  created_at timestamp
  updated_at timestamp
}

Table brand_stories {
  brand_id bigint
  story_id bigint
}

Table pages {
  id bigint [primary key]
  title varchar
  slug varchar [unique]
  content text
  is_active boolean
  created_at timestamp
  updated_at timestamp
}

// Системное (Tags, Media)
Table tags {
  id bigint [primary key]
  name json
  slug json
  type varchar
  order_column int
  created_at timestamp
  updated_at timestamp
}

Table taggables {
  tag_id bigint
  taggable_type varchar
  taggable_id bigint
}

Table media {
  id bigint [primary key]
  model_type varchar
  model_id bigint
  collection_name varchar
  name varchar
  file_name varchar
  mime_type varchar
  disk varchar
  size bigint
  manipulations json
  custom_properties json
  responsive_images json
  order_column int
  created_at timestamp
  updated_at timestamp
}

// Отношения (Ref)
Ref: users.region_id > regions.id
Ref: users.currency_id > currencies.id
Ref: user_balances.user_id > users.id
Ref: user_balances.currency_id > currencies.id

Ref: companies.user_id > users.id
Ref: company_bank_accounts.company_id > companies.id
Ref: delivery_addresses.user_id > users.id

Ref: categories.parent_id > categories.id
Ref: brands.parent_id > brands.id
Ref: products.brand_id > brands.id
Ref: products.model_id > product_models.id
Ref: products.size_chart_id > size_charts.id
Ref: product_models.brand_id > brands.id

Ref: category_product.category_id > categories.id
Ref: category_product.product_id > products.id

Ref: product_warehouse.product_id > products.id
Ref: product_warehouse.warehouse_id > warehouses.id
Ref: region_warehouse.region_id > regions.id
Ref: region_warehouse.warehouse_id > warehouses.id

Ref: attribute_values.attribute_id > attributes.id
Ref: product_attribute_values.product_id > products.id
Ref: product_attribute_values.attribute_id > attributes.id
Ref: product_attribute_values.attribute_value_id > attribute_values.id

Ref: brand_size_chart.brand_id > brands.id
Ref: brand_size_chart.size_chart_id > size_charts.id

Ref: product_barcodes.product_id > products.id
Ref: product_certificate.product_id > products.id
Ref: product_certificate.certificate_id > certificates.id
Ref: product_segment.product_id > products.id
Ref: product_segment.segment_id > segments.id

Ref: orders.user_id > users.id
Ref: orders.company_id > companies.id
Ref: orders.delivery_address_id > delivery_addresses.id
Ref: orders.cart_id > carts.id
Ref: order_items.order_id > orders.id
Ref: order_items.product_id > products.id

Ref: carts.user_id > users.id
Ref: cart_items.cart_id > carts.id
Ref: cart_items.product_id > products.id

Ref: returns.user_id > users.id
Ref: return_items.return_id > returns.id
Ref: return_items.product_id > products.id

Ref: wishlist_items.user_id > users.id
Ref: wishlist_items.product_id > products.id
Ref: favorites.user_id > users.id
Ref: favorites.product_id > products.id

Ref: product_promotion.product_id > products.id
Ref: product_promotion.promotion_id > promotions.id
Ref: discount_product.discount_id > discounts.id
Ref: discount_product.product_id > products.id
Ref: discount_user.discount_id > discounts.id
Ref: discount_user.user_id > users.id
Ref: product_product_selection.product_id > products.id
Ref: product_product_selection.product_selection_id > product_selections.id

Ref: story_slides.story_id > stories.id
Ref: brand_stories.brand_id > brands.id
Ref: brand_stories.story_id > stories.id

Ref: taggables.tag_id > tags.id
```
