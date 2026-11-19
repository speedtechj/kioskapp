<?php

namespace App\Filament\Pages;

use App\Models\Business;
use App\Models\Kiosk;
use App\Models\Order;
use App\Models\User;
use App\Services\BrandImage;
use App\Services\UrlSlugs;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;

class Businesses extends Page
{
    use WithFileUploads;

    protected static ?string $title = 'Business Control';

    public string $search = '';

    #[Locked]
    public ?int $selectedId = null;

    #[Locked]
    public ?int $logoKioskId = null;

    public string $businessName = '';

    public string $kioskName = '';

    public $businessLogo = null;

    public $kioskLogo = null;

    protected string $view = 'filament.pages.businesses';

    protected static ?string $navigationLabel = 'Businesses';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    public string $name = '';

    public string $businessSlug = '';

    public string $ownerName = '';

    public string $email = '';

    public string $password = '';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    public function createBusiness(): void
    {
        abort_unless(auth()->user()?->is_super_admin, 403);
        $this->validate(['businessSlug' => 'nullable|string|max:120', 'name' => 'required|string|max:100', 'ownerName' => 'required|string|max:100', 'email' => ['required', 'email', Rule::unique('users', 'email')], 'password' => 'required|string|min:12|max:128']);
        $business = DB::transaction(function () {
            $b = Business::create(['name' => $this->name, 'slug' => UrlSlugs::validate(new Business(['name' => $this->name]), $this->businessSlug, 'businessSlug')]);
            $u = User::create(['name' => $this->ownerName, 'email' => $this->email, 'password' => $this->password]);
            $b->users()->attach($u, ['role' => 'owner']);

            return $b;
        });
        $this->selectBusiness($business->id);
        $this->dispatch('close-modal', id: 'create-business');
        $this->reset('businessSlug', 'name', 'ownerName', 'email', 'password');
        Notification::make()->title('Business created. The owner can sign in and add kiosks.')->success()->send();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $first = Business::orderBy('name')->first();
        if ($first) {
            $this->selectBusiness($first->id);
        }
    }

    public function getBusinesses()
    {
        abort_unless(static::canAccess(), 403);
        $term = trim($this->search);

        return Business::withCount('kiosks')->when($term !== '', fn ($query) => $query
            ->where(fn ($match) => $match->where('name', 'like', '%'.$term.'%')->orWhere('slug', 'like', '%'.$term.'%')))
            ->orderBy('name')->get();
    }

    public function getTotals(): array
    {
        abort_unless(static::canAccess(), 403);

        return [Business::count(), Kiosk::whereNull('archived_at')->count(), Business::whereDoesntHave('kiosks', fn ($query) => $query->whereNull('archived_at'))->count()];
    }

    public function selectedBusiness(): ?Business
    {
        abort_unless(static::canAccess(), 403);

        return $this->selectedId ? Business::find($this->selectedId) : null;
    }

    public function selectBusiness(int $id): void
    {
        abort_unless(static::canAccess(), 403);
        $business = Business::findOrFail($id);
        $this->selectedId = $business->id;
        $this->businessName = $business->name;
        $this->logoKioskId = $business->kiosks()->orderBy('name')->value('id');
        $this->reset('businessLogo', 'kioskLogo', 'kioskName');
        $this->resetValidation();
    }

    public function canDeleteBusiness(Business $business): bool
    {
        return static::canAccess()
            && ! $business->kiosks()->exists()
            && ! $business->products()->exists()
            && ! $business->users()->wherePivot('role', '!=', 'owner')->exists()
            && ! Order::where('business_id', $business->id)->exists();
    }

    public function deleteBusiness(int $id): void
    {
        abort_unless(static::canAccess(), 403);
        abort_unless($id === $this->selectedId, 404);

        $deleted = DB::transaction(function () use ($id): bool {
            $business = Business::whereKey($id)->lockForUpdate()->first();
            if (! $business || ! $this->canDeleteBusiness($business)) {
                return false;
            }

            DB::table('url_slug_aliases')->updateOrInsert(
                ['resource_type' => 'businesses', 'scope_id' => 0, 'slug' => $business->slug],
                ['resource_id' => $business->id],
            );
            $business->delete();

            return true;
        });

        $this->dispatch('close-modal', id: 'delete-business-'.$id);
        if (! $deleted) {
            Notification::make()->title('Business could not be deleted. Refresh and check its kiosks, products, staff and orders.')->warning()->send();

            return;
        }

        $this->reset('selectedId', 'logoKioskId', 'businessName', 'businessLogo', 'kioskLogo', 'kioskName');
        $this->resetValidation();
        if ($next = Business::orderBy('name')->first()) {
            $this->selectBusiness($next->id);
        }
        Notification::make()->title('Business deleted')->success()->send();
    }

    public function businessOwners()
    {
        return $this->selectedBusiness()?->users()->wherePivot('role', 'owner')->orderBy('users.name')->get()
            ?? collect();
    }

    public function changeAvailabilityAction(): Action
    {
        return Action::make('changeAvailability')
            ->label('Change status')
            ->color('gray')
            ->outlined()
            ->size('sm')
            ->modalHeading('Change business availability')
            ->extraModalWindowAttributes(['class' => 'availability-modal'])
            ->modalDescription(function (array $arguments): string {
                $business = $this->availabilityBusiness($arguments);
                $orders = Order::where('business_id', $business->id)->whereNotIn('status', ['completed', 'cancelled'])->count();
                $shifts = \App\Models\RegisterShift::whereIn('kiosk_id', $business->kiosks()->select('id'))->whereNull('closed_at')->count();

                return $business->name.' · '.$orders.' open '.Str::plural('order', $orders).' · '.$shifts.' active cashier '.Str::plural('shift', $shifts)
                    .'. Coming Soon and Maintenance block customer and staff access. Existing orders and shifts are kept.';
            })
            ->modalSubmitActionLabel('Save status')
            ->fillForm(fn (array $arguments): array => ['availability' => $this->availabilityBusiness($arguments)->availability])
            ->schema([
                \Filament\Forms\Components\Radio::make('availability')
                    ->label('Select availability')
                    ->options(\App\Services\BusinessAvailability::LABELS)
                    ->in(array_keys(\App\Services\BusinessAvailability::LABELS))
                    ->required(),
            ])
            ->action(function (array $data, array $arguments): void {
                $business = $this->availabilityBusiness($arguments);
                $next = $data['availability'];
                DB::transaction(function () use ($business, $next): void {
                    $locked = Business::lockForUpdate()->findOrFail($business->id);
                    $previous = $locked->availability;
                    if ($previous === $next) {
                        return;
                    }
                    $locked->forceFill(['availability' => $next])->save();
                    \App\Services\BusinessAudit::record($locked->id, 'Business availability changed', $locked->name, details: [
                        'Before' => \App\Services\BusinessAvailability::LABELS[$previous],
                        'After' => \App\Services\BusinessAvailability::LABELS[$next],
                    ]);
                }, 3);

                Notification::make()->title('Business availability saved')->success()->send();
            });
    }

    private function availabilityBusiness(array $arguments): Business
    {
        abort_unless(static::canAccess(), 403);

        return Business::findOrFail((int) ($arguments['business'] ?? 0));
    }

    public function editOwnerEmailAction(): Action
    {
        return Action::make('editOwnerEmail')
            ->modalHeading('Edit owner email')
            ->modalDescription(fn (array $arguments) => $this->ownerForAction($arguments)->name.' · Changes apply to every business linked to this account.')
            ->modalSubmitActionLabel('Save email')
            ->schema(fn (array $arguments) => [
                TextInput::make('email')->label('Sign-in email')->email()->required()->maxLength(255)
                    ->unique(User::class, 'email', ignorable: $this->ownerForAction($arguments)),
            ])
            ->fillForm(fn (array $arguments) => ['email' => $this->ownerForAction($arguments)->email])
            ->action(function (array $data, array $arguments): void {
                $owner = $this->ownerForAction($arguments);
                if ($owner->email !== $data['email']) {
                    $owner->forceFill(['email' => $data['email'], 'email_verified_at' => null])->save();
                }
                Notification::make()->title('Owner email updated')->success()->send();
            });
    }

    public function resetOwnerPasswordAction(): Action
    {
        return Action::make('resetOwnerPassword')
            ->modalHeading('Reset owner password')
            ->modalDescription(function (array $arguments): string {
                $owner = $this->ownerForAction($arguments);

                return $owner->name.' · '.$owner->email.' — The owner will use the new password at their next sign-in.';
            })
            ->modalSubmitActionLabel('Reset password')
            ->schema([
                TextInput::make('password')->label('New password')->password()->revealable()
                    ->autocomplete('new-password')->required()->minLength(12)->maxLength(128)
                    ->helperText('At least 12 characters.')
                    ->rule(fn () => function (string $attribute, string $value, \Closure $fail): void {
                        if (config('hashing.driver', 'bcrypt') === 'bcrypt' && strlen($value) > 72) {
                            $fail('Use a password of at most 72 bytes.');
                        }
                    })->confirmed(),
                TextInput::make('password_confirmation')->label('Confirm new password')->password()->revealable()
                    ->autocomplete('new-password')->required()->dehydrated(false),
            ])
            ->action(function (array $data, array $arguments): void {
                $owner = $this->ownerForAction($arguments);
                $owner->password = $data['password'];
                $owner->setRememberToken(Str::random(60));
                $owner->save();
                Notification::make()->title('Owner password reset')->success()->send();
            });
    }

    private function ownerForAction(array $arguments): User
    {
        $business = $this->selectedBusiness();
        abort_unless($business && $business->id === ($arguments['business'] ?? null), 404);

        return $business->users()->wherePivot('role', 'owner')->where('users.is_super_admin', false)
            ->findOrFail($arguments['owner'] ?? 0);
    }

    public function selectKiosk(int $id): void
    {
        $this->logoKioskId = $this->kiosk($id)->id;
        $this->reset('kioskLogo');
        $this->resetValidation('kioskLogo');
    }

    private function kiosk(int $id): Kiosk
    {
        $business = $this->selectedBusiness();
        abort_unless($business, 404);

        return $business->kiosks()->findOrFail($id);
    }

    public function saveBusinessDetails(): void
    {
        $business = $this->selectedBusiness();
        abort_unless($business, 404);
        $this->businessName = trim($this->businessName);
        $this->validate(['businessName' => 'required|string|max:100', 'businessLogo' => BrandImage::rules()]);
        $business->name = $this->businessName;
        if ($this->businessLogo) {
            $business->logo = BrandImage::store($this->businessLogo, "business-logos/{$business->id}");
        }
        $business->save();
        $this->reset('businessLogo');
        Notification::make()->title('Business details saved')->success()->send();
    }

    public function saveKioskLogo(): void
    {
        $kiosk = $this->kiosk($this->logoKioskId ?? 0);
        $this->validate(['kioskLogo' => [...BrandImage::rules(192, true), 'required']]);
        $kiosk->app_icon_key = BrandImage::kioskIcon($this->kioskLogo, $kiosk->business_id, $kiosk->id);
        $kiosk->save();
        $this->reset('kioskLogo');
        Notification::make()->title('Kiosk logo saved')->success()->send();
    }

    public function useBusinessLogo(): void
    {
        $kiosk = $this->kiosk($this->logoKioskId ?? 0);
        $kiosk->app_icon_key = null;
        $kiosk->save();
        $this->reset('kioskLogo');
        Notification::make()->title('Kiosk now uses the business logo or Gather default')->success()->send();
    }

    public function addKiosk(): void
    {
        $business = $this->selectedBusiness();
        abort_unless($business, 404);
        $this->kioskName = trim($this->kioskName);
        $this->validate(['kioskName' => 'required|string|max:100']);
        $kiosk = DB::transaction(function () use ($business) {
            $kiosk = $business->kiosks()->create(['name' => $this->kioskName]);
            $kiosk->products()->sync($business->products()->pluck('id')->all());
            foreach (range(1, 8) as $number) {
                $kiosk->tables()->create(['name' => sprintf('Table %02d', $number), 'token' => (string) Str::uuid()]);
            }

            return $kiosk;
        });
        $this->selectKiosk($kiosk->id);
        $this->reset('kioskName');
        Notification::make()->title('Kiosk created')->success()->send();
    }

    public function toggleKiosk(int $id): void
    {
        DB::transaction(function () use ($id) {
            $kiosk = Kiosk::whereKey($this->kiosk($id)->id)->lockForUpdate()->firstOrFail();
            abort_if($kiosk->archived_at, 422);
            $kiosk->update(['active' => ! $kiosk->active]);
        });
    }

    public function removeKiosk(int $id): void
    {
        $scopedKiosk = $this->kiosk($id);
        $archived = DB::transaction(function () use ($scopedKiosk) {
            $kiosk = Kiosk::whereKey($scopedKiosk->id)->lockForUpdate()->firstOrFail();
            if ($kiosk->orders()->exists()) {
                $kiosk->archived_at = now();
                $kiosk->active = false;
                $kiosk->save();

                return true;
            }
            DB::table('url_slug_aliases')->updateOrInsert(
                ['resource_type' => 'kiosks', 'scope_id' => $kiosk->business_id, 'slug' => $kiosk->slug],
                ['resource_id' => $kiosk->id],
            );
            $kiosk->delete();

            return false;
        });
        if (! $archived && $this->logoKioskId === $id) {
            $this->logoKioskId = $this->selectedBusiness()->kiosks()->orderBy('name')->value('id');
            $this->reset('kioskLogo');
        }
        $this->dispatch('close-modal', id: 'remove-kiosk-'.$id);
        Notification::make()->title($archived ? 'Kiosk archived. Order history preserved.' : 'Kiosk deleted')->success()->send();
    }
}
