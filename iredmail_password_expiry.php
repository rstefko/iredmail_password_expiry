<?php

class iredmail_password_expiry extends rcube_plugin
{
    private $rc;

    public function init()
    {
        $this->rc = rcmail::get_instance();

        $this->load_config();

        $this->add_hook('render_page', [$this, 'render_page']);
    }

    public function render_page($args)
    {
        // Do not display the warning on the login page.
        if (($args['template'] ?? '') === 'login') {
            return $args;
        }

        try {
            $username = $this->rc->get_user_name();

            if (empty($username)) {
                return $args;
            }

            $host = $this->rc->config->get('expiry_db_host');
            $dbname = $this->rc->config->get('expiry_db_name');
            $dbuser = $this->rc->config->get('expiry_db_user');
            $dbpass = $this->rc->config->get('expiry_db_password');

            $pdo = new PDO(
                "mysql:host={$host};dbname={$dbname};charset=utf8mb4",
                $dbuser,
                $dbpass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );

            $stmt = $pdo->prepare(
                'SELECT passwordlastchange
                 FROM mailbox
                 WHERE username = :username
                 LIMIT 1'
            );

            $stmt->execute(['username' => $username]);

            $row = $stmt->fetch();

            if (!$row || empty($row['passwordlastchange'])) {
                return $args;
            }

            $last_change = $row['passwordlastchange'];

            // Ignore an unset MySQL zero date.
            if ($last_change === '0000-00-00 00:00:00') {
                return $args;
            }

            $expire_days = (int) $this->rc->config->get(
                'expiry_days',
                90
            );

            $warning_days = (int) $this->rc->config->get(
                'expiry_warning_days',
                10
            );

            $changed = new DateTimeImmutable($last_change);

            $expires = $changed->modify(
                "+{$expire_days} days"
            );

            $now = new DateTimeImmutable('now', $changed->getTimezone());

            $seconds_left = $expires->getTimestamp() - $now->getTimestamp();

            $days_left = (int) ceil($seconds_left / 86400);

            // Display warning only within the configured warning period.
            if ($days_left > $warning_days) {
                return $args;
            }

            $password_url = $this->rc->url([
                '_task' => 'settings',
                '_action' => 'plugin.password',
            ]);

            $change_password_link = html::a(
                ['href' => $password_url],
                'Please change it'
            );

            if ($days_left <= 0) {
                $message = 'Your password has expired. ' . $change_password_link . ' immediately.';
                $type = 'error';
            } else {
                $message = sprintf(
                    'Your password will expire in %d day(s).' . $change_password_link . '.',
                    $days_left
                );
                $type = 'notice';
            }

            $this->rc->output->show_message(
                $message,
                $type
            );

        } catch (Throwable $e) {
            // Log the error without exposing database credentials.
            rcube::write_log(
                'errors',
                'iRedMail password expiry plugin: ' . $e->getMessage()
            );
        }

        return $args;
    }
}
