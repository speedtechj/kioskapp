<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\User;
use App\Services\PlaceOrder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Demo seeding is restricted to local/testing environments.');
        }
        $password = env('DEMO_PASSWORD');
        if (! $password || strlen($password) < 12) {
            throw new \RuntimeException('Set DEMO_PASSWORD to at least 12 characters in .env before seeding.');
        }
        if (Business::exists()) {
            throw new \RuntimeException('Demo data already exists. Seeding is deliberately not destructive.');
        }
        DB::transaction(function () use ($password) {
            $user = function ($name, $email, $super = false) use ($password) {
                $u = User::create(['name' => $name, 'email' => $email, 'password' => $password]);
                if ($super) {
                    $u->forceFill(['is_super_admin' => true])->save();
                }

return $u;
            };
            $user('Platform Admin', 'platform@gather.test', true);
            $owner = $user('Jamie Santos', 'owner@gather.test');
            $cashier = $user('Alex Cruz', 'cashier@gather.test');
            $kitchen = $user('Sam Reyes', 'kitchen@gather.test');
            $manager = $user('Morgan Lee', 'manager@gather.test');
            $b = Business::create(['name' => 'Gather Restaurant', 'slug' => 'gather']);
            foreach ([[$owner, 'owner'], [$cashier, 'cashier'], [$kitchen, 'kitchen'], [$manager, 'manager']] as [$u,$role]) {
                $b->users()->attach($u, ['role' => $role]);
            }
            $main = $b->kiosks()->create(['name' => 'Main branch', 'address' => 'Makati, Metro Manila', 'service_charge_bps' => 600]);
            $second = $b->kiosks()->create(['name' => 'Garden kiosk', 'address' => 'Imus, Cavite']);
            $cashier->kiosks()->attach($main);
            $kitchen->kiosks()->attach($main);
            $manager->kiosks()->attach([$main->id, $second->id]);
            $catalog = [['Chicken Rice Bowl', 'Rice bowls', 'Grilled chicken, seasonal greens, and signature rice.', 189, 'bowl'], ['Classic Burger', 'Burgers', 'Beef patty, cheddar, lettuce, and our house sauce.', 159, 'burger'], ['Iced Latte', 'Drinks', 'Espresso, milk, and a little moment of calm.', 120, 'latte'], ['Garden Bowl', 'Rice bowls', 'Fresh vegetables, bright flavors, and a nourishing base.', 179, 'beef'], ['Crispy Chicken Burger', 'Burgers', 'Crispy chicken, crunchy slaw, and a soft toasted bun.', 169, 'chicken'], ['Truffle Fries', 'Sides', 'Golden fries, truffle seasoning, and parmesan.', 139, 'fries']];
            foreach ($catalog as [$name,$cat,$desc,$price,$img]) {
                $p = $b->products()->create(['name' => $name, 'category' => $cat, 'description' => $desc, 'price' => $price * 100, 'image' => '/images/'.$img.'.jpg']);
                $main->products()->attach($p, ['stock' => 100]);
                $second->products()->attach($p, ['stock' => 60]);
            }
            foreach ([$main, $second] as $k) {
                foreach (range(1, 8) as $n) {
                    $k->tables()->create(['name' => 'Table '.str_pad($n, 2, '0', STR_PAD_LEFT), 'token' => (string) Str::uuid()]);
                }
            }
            Auth::login($owner);
            \App\Services\RegisterShifts::open($main, 0);
            \App\Services\RegisterShifts::open($second, 0);

            $products = $b->products()->get();
            foreach (range(0, 34) as $n) {
                $k = $n % 4 === 0 ? $second : $main;
                $p = $products[$n % 6];
                $q = $products[($n + 2) % 6];
                $table = $k->tables()->first();
                $o = app(PlaceOrder::class)->handle($k, [$p->id => 1 + ($n % 2), $q->id => 1], (string) Str::uuid(), $table, $n % 5 === 0 ? null : 'cash', 'Guest');
                $days = $n < 13 ? 0 : 1 + $n % 6;
                $o->forceFill(['created_at' => now()->subDays($days)->subMinutes($n * 5), 'updated_at' => now()->subDays($days), 'status' => $days === 0 ? ['received', 'preparing', 'ready', 'completed'][$n % 4] : 'completed'])->save();
            }Auth::logout();
            $other = Business::create(['name' => 'Juniper Cafe', 'slug' => 'juniper']);
            $otherOwner = $user('Taylor Kim', 'other@gather.test');
            $other->users()->attach($otherOwner, ['role' => 'owner']);
            $k = $other->kiosks()->create(['name' => 'Juniper Central']);
            $k->tables()->create(['name' => 'Table 01', 'token' => (string) Str::uuid()]);
            $p = $other->products()->create(['name' => 'Juniper House Coffee', 'category' => 'Drinks', 'description' => 'A different business, kept separate.', 'price' => 9500, 'image' => '/images/latte.jpg']);
            $k->products()->attach($p,['stock' => 80]);
        });
    }
}
