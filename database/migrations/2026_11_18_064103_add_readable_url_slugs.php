<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('url_slug_aliases')) {
            Schema::create('url_slug_aliases', function (Blueprint $t) {
                $t->id();
                $t->string('resource_type', 40);
                $t->unsignedBigInteger('scope_id')->default(0);
                $t->unsignedBigInteger('resource_id');
                $t->string('slug', 120);
                $t->unique(['resource_type', 'scope_id', 'slug'], 'url_slug_alias_unique');
            });
        }
        foreach (['kiosks', 'dining_tables', 'orders'] as $table) {
            if (! Schema::hasColumn($table, 'slug')) {
                Schema::table($table, fn (Blueprint $t) => $t->string('slug', 120)->nullable());
            }
        }
        foreach (['kiosks' => 'business_id', 'dining_tables' => 'kiosk_id', 'orders' => null] as $table => $scope) {
            DB::table($table)->where(fn ($q) => $q->whereNull('slug')->orWhere('slug', ''))->orderBy('id')->chunkById(100, function ($rows) use ($table, $scope) {
                foreach ($rows as $row) {
                    if ($table === 'orders') {
                        $slug = 'order-'.bin2hex(random_bytes(16));
                    } else {
                        $base = substr(Str::slug($row->name), 0, 90);
                        if ($base === '' || ctype_digit($base)) {
                            $base = ($table === 'kiosks' ? 'kiosk' : 'table').($base ? '-'.$base : '');
                        }$slug = $base;
                        $i = 2;
                        while (DB::table($table)->where($scope, $row->$scope)->where('slug', $slug)->where('id', '!=', $row->id)->exists()) {
                            $slug = $base.'-'.$i++;
                        }
                    }
                    DB::table($table)->where('id', $row->id)->update(['slug' => $slug]);
                }
            });
        }
        foreach (['kiosks' => ['business_id', 'slug'], 'dining_tables' => ['kiosk_id', 'slug'], 'orders' => ['slug']] as $table => $columns) {
            $name = $table.'_readable_slug_unique';
            if (! Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $t) => $t->unique($columns, $name));
            }
        }
    }

    public function down(): void
    {
        foreach (['kiosks', 'dining_tables', 'orders'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropUnique($table.'_readable_slug_unique');
                $t->dropColumn('slug');
            });
        }Schema::dropIfExists('url_slug_aliases');
    }
};
