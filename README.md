# Laravel CRUD Generator

#### Laravel CRUD Generator - bu paket Laravelda model uchun CRUD operatsiyalarini avtomatik yaratish jarayonini soddalashtiradi. Bu paket Controllerlar, Requestlar, Viewlar, Route'lar avtomatik yaratadi model va migratsiyalarni oldindan yaratish zarur

### O‘rnatish

Paketni composer orqali o‘rnatish:

```bash
composer require abdugoffor/laravel-crud-generator:dev-main
```
### Namuna:
#### 1. Model va Migratsiya Yaratish

```bash
php artisan make:model Post -m
```
#### 2. Migratsiyada Maydonlarni Qo‘shish

``` bash
public function up()
{
    Schema::create('posts', function (Blueprint $table) {
        $table->id();
        $table->string('title');
        $table->text('description');
        $table->timestamps();
    });
}
```
#### 3. Migratsiyani ishga tushirish:

```bash
php artisan migrate
```

### Agar select bo'lib chiqishi kerak maydonlar bo'lsa public $enumValues massivini ichida bo'lishi zarur
```bash
public $enumValues = [
    'status' => [
        'values' => ['draft', 'published', 'archived'],
        'default' => 'draft',
    ],
    'category' => [
        'values' => ['news', 'blog', 'review'],
        'default' => 'news',
    ],
];
```
#### 4. CRUD Kodini Avtomatik Yaratish

```bash
php artisan make:simple-crud Post
```
#### 5. API CRUD Kodini Avtomatik Yaratish

```bash
php artisan make:simple-api-crud Post
```
#### 5. Laravelni Ishga Tushirish
```bash
php artisan serve
```
### http://127.0.0.1:8000/posts sahifasiga tashrif buyurib, CRUD tizimingizni ishlatishingiz mumkin
