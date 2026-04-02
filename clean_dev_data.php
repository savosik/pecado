<?php

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Category;
use App\Models\IndividualPrice;
use App\Models\Product;
use App\Models\ProductModel;
use App\Models\User;
use Illuminate\Support\Facades\DB;

DB::statement('SET FOREIGN_KEY_CHECKS=0;');

echo "Cleaning products...\n";
Product::truncate();

echo "Cleaning models...\n";
ProductModel::truncate();

echo "Cleaning categories...\n";
Category::truncate();

echo "Cleaning brands...\n";
Brand::truncate();

echo "Cleaning attributes...\n";
Attribute::truncate();
AttributeValue::truncate();
DB::table('attribute_value_product')->truncate();

echo "Cleaning individual prices...\n";
IndividualPrice::truncate();

echo "Cleaning users (keeping admin)...\n";
User::where('email', '!=', 'admin@pecado.ru')->delete();

DB::statement('SET FOREIGN_KEY_CHECKS=1;');

echo "Done.\n";
