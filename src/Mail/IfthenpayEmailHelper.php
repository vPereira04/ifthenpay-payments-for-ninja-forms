<?php

declare(strict_types=1);

namespace Ifthenpay\NinjaForms\Mail;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Sends the "Request Activation" email for a not-yet-provisioned method.
 * This shape is shared across every ifthenpay integration, so I keep it
 * as-is here — only the data plugged in changes per integration.
 */
final class IfthenpayEmailHelper
{
    public const SUPPORT_EMAIL = 'v.pereira.contacto@gmail.com';
    // public const SUPPORT_EMAIL = 'suporte@ifthenpay.com';

    private function __construct()
    {
    }

    /**
     * @param array{
     *     gateway_key: string,
     *     entity: string,
     *     backoffice_key: string,
     *     customer_email: string,
     *     site_url: string,
     *     site_name: string,
     *     wp_version: string,
     *     ninja_forms_version: string,
     *     plugin_version: string,
     * } $data
     */
    public static function send_activation_email(array $data): bool
    {
        $entity  = strtoupper(sanitize_text_field($data['entity'] ?? ''));
        $site_url = esc_url_raw($data['site_url'] ?? home_url('/'));
        $recipient = self::get_support_email($data);

        $subject = sprintf('[dev_ifthenpay] [%s]: Ativacao de Servico', $entity);

        $items = [
            'Chave de acesso ao backoffice:' => esc_html($data['backoffice_key'] ?? ''),
            'Gateway Key:'                   => esc_html($data['gateway_key'] ?? ''),
            'Email Cliente:'                 => esc_html($data['customer_email'] ?? ''),
            'Metodo a ativar:'               => esc_html($entity),
            'Loja online:'                   => esc_url($site_url),
            'Plataforma ecommerce:'          => sprintf(
                'WordPress %s / Ninja Forms v%s',
                esc_html($data['wp_version'] ?? ''),
                esc_html($data['ninja_forms_version'] ?? '')
            ),
            'Versao do Modulo ifthenpay:'    => esc_html($data['plugin_version'] ?? ''),
            'Atualizar Conta Cliente:'        => 'Apos adicionar o metodo nao precisa tomar mais nenhuma acao, este metodo ficara disponivel para selecao na pagina de configuracao da extensao.',
        ];

        ob_start();
        ?>
        <div style="
            font-family: Arial, sans-serif;
            color: #333;
            background-color: #f9f9f9;
            padding: 20px;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            max-width: 600px;
            margin: auto;
        ">
            <h2 style="margin-top: 0; font-size: 20px; line-height: 1.2;">
                Ativar metodo de pagamento para a Gateway
                <span style="color: #d32f2f;"><?php echo esc_html($data['gateway_key'] ?? ''); ?></span>
            </h2>

            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%; border-collapse: collapse;">
                <?php foreach ($items as $label => $value) : ?>
                    <tr>
                        <td style="padding: 8px 0; vertical-align: top; width: 200px; font-weight: bold;">
                            <?php echo esc_html($label); ?>
                        </td>
                        <td style="padding: 8px 0;">
                            <?php echo esc_html($value); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>

            <p style="margin-top: 20px; font-size: 12px; color: #777; text-align: center;">
                Pedido gerado automaticamente pelo modulo ifthenpay
            </p>
        </div>
        <?php
        $body = (string) ob_get_clean();

        $host = wp_parse_url($site_url, PHP_URL_HOST);
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . esc_html($data['site_name'] ?? '') . ' <no-reply@' . $host . '>',
        ];

        return wp_mail($recipient, $subject, $body, $headers);
    }

    /**
     * @param array<string, string> $data
     */
    private static function get_support_email(array $data): string
    {
        $email = apply_filters('iftp_nf_support_email', self::SUPPORT_EMAIL, $data);

        return is_string($email) && is_email($email) ? $email : self::SUPPORT_EMAIL;
    }
}
