<?php

namespace App\Console\Commands;

use App\Actions\Companies\CreateInitialAdmin as CreateInitialAdminAction;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\PermissionSeeder;
use DomainException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Throwable;

#[Signature('app:create-initial-admin
    {--name= : Nombre completo del administrador}
    {--email= : Correo del administrador}
    {--password= : Contraseña temporal; omitir para ingresarla de forma oculta}
    {--company= : Nombre de la empresa inicial}
    {--currency=BOB : Código ISO 4217 de la moneda base}
    {--timezone= : Zona horaria de la empresa}
    {--locale= : Idioma de la empresa}')]
#[Description('Crea de forma segura el primer administrador y su empresa')]
class CreateInitialAdmin extends Command
{
    public function __construct(private CreateInitialAdminAction $createInitialAdmin)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $input = $this->validatedInput();

        if ($input === null) {
            return self::FAILURE;
        }

        foreach ([CurrencySeeder::class, ModuleSeeder::class, PermissionSeeder::class] as $seeder) {
            if ($this->call('db:seed', ['--class' => $seeder, '--force' => true]) !== self::SUCCESS) {
                $this->error('No se pudo preparar el catálogo global requerido.');

                return self::FAILURE;
            }
        }

        try {
            $result = $this->createInitialAdmin->handle($input);
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('No se pudo completar el bootstrap. No se guardaron registros parciales.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Administrador inicial creado correctamente.');
        $this->line("Usuario: {$result['user']->email}");
        $this->line("Empresa: {$result['company']->name}");
        $this->line("Membership: {$result['membership']->getKey()} (owner activa)");
        $this->line("Rol: {$result['role']->name}");
        $this->warn('Deberá cambiar la contraseña en el primer ingreso.');

        return self::SUCCESS;
    }

    /**
     * @return array{name: string, email: string, password: string, company_name: string, currency_code: string, timezone: string, locale: string}|null
     */
    private function validatedInput(): ?array
    {
        $passwordOption = $this->option('password');
        $password = is_string($passwordOption) && $passwordOption !== ''
            ? $passwordOption
            : $this->secret('Contraseña temporal');
        $passwordConfirmation = is_string($passwordOption) && $passwordOption !== ''
            ? $passwordOption
            : $this->secret('Confirmar contraseña temporal');

        if (is_string($passwordOption) && $passwordOption !== '') {
            $this->warn('Evita --password en terminales compartidas porque puede quedar en el historial del shell.');
        }

        $validator = Validator::make([
            'name' => $this->optionOrAsk('name', 'Nombre completo del administrador'),
            'email' => Str::lower($this->optionOrAsk('email', 'Correo del administrador')),
            'password' => $password,
            'password_confirmation' => $passwordConfirmation,
            'company_name' => $this->optionOrAsk('company', 'Nombre de la empresa'),
            'currency_code' => $this->option('currency'),
            'timezone' => $this->option('timezone') ?: config('tenancy.registration.timezone'),
            'locale' => $this->option('locale') ?: config('tenancy.registration.locale'),
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::default(), 'confirmed'],
            'company_name' => ['required', 'string', 'max:255'],
            'currency_code' => ['required', 'string', 'size:3'],
            'timezone' => ['required', 'string', 'timezone:all'],
            'locale' => ['required', 'string', 'max:10'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return null;
        }

        /** @var array{name: string, email: string, password: string, company_name: string, currency_code: string, timezone: string, locale: string} $validated */
        $validated = $validator->safe()->only([
            'name',
            'email',
            'password',
            'company_name',
            'currency_code',
            'timezone',
            'locale',
        ]);

        return $validated;
    }

    private function optionOrAsk(string $option, string $question): string
    {
        $value = $this->option($option);

        return is_string($value) && $value !== '' ? $value : (string) $this->ask($question);
    }
}
