<?php

declare(strict_types=1);

namespace Lits\Action;

use Lits\Action;
use Lits\Config\FormConfig;
use Lits\Exception\FailedSendingException;
use Lits\Exception\InvalidDataException;
use Lits\Mail;
use Lits\Service\ActionService;
use Safe\Exceptions\DatetimeException;
use Safe\Exceptions\PcreException;
use Slim\Exception\HttpInternalServerErrorException;
use Slim\Http\Response;
use Slim\Http\ServerRequest;

use function Safe\date;
use function Safe\strtotime;

final class IndexAction extends Action
{
    public function __construct(ActionService $service, private Mail $mail)
    {
        parent::__construct($service);
    }

    /** @throws HttpInternalServerErrorException */
    public function action(): void
    {
        try {
            $this->render(
                $this->template(),
                ['post' => $this->session->get('post')],
            );

            $this->session->remove('post');
        } catch (\Throwable $exception) {
            throw new HttpInternalServerErrorException(
                $this->request,
                null,
                $exception,
            );
        }
    }

    /**
     * @param array<string, string> $data
     * @throws HttpInternalServerErrorException
     */
    public function post(
        ServerRequest $request,
        Response $response,
        array $data,
    ): Response {
        $this->setup($request, $response, $data);
        $this->redirect();

        $post = $this->request->getParsedBody();

        try {
            $post = $this->granted('by') +
                $this->granted('to') +
                $this->permission() +
                $this->expire();

            $this->send($post);

            unset($post['granted_to_name']);
            unset($post['granted_to_id']);
            unset($post['granted_to_email']);
        } catch (InvalidDataException $exception) {
            $this->message('failure', $exception->getMessage());
        } catch (\Throwable $exception) {
            throw new HttpInternalServerErrorException(
                $this->request,
                'Could not process posted data',
                $exception,
            );
        }

        $this->session->set('post', $post);

        return $this->response;
    }

    /**
     * @return array<string, ?string>
     * @throws InvalidDataException
     * @throws PcreException
     */
    private function granted(string $person): array
    {
        $post = [];

        foreach (['name', 'id', 'email'] as $field) {
            $key = 'granted_' . $person . '_' . $field;

            $post[$key] = $this->value($key, true);
        }

        $name = $post['granted_' . $person . '_name'];

        if (\is_null($name)) {
            throw new InvalidDataException(
                'Authorization granted ' . $person .
                ': Name must be specified',
            );
        }

        $id = $post['granted_' . $person . '_id'];

        if (\is_null($id)) {
            throw new InvalidDataException(
                'Authorization granted ' . $person .
                ': ID Number must be specified',
            );
        }

        $email = $post['granted_' . $person . '_email'];

        if (\filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidDataException(
                'Authorization granted ' . $person .
                ': Email must be valid',
            );
        }

        return $post;
    }

    /**
     * @return array<string, ?string>
     * @throws InvalidDataException
     */
    private function permission(): array
    {
        $permission = $this->value('granted_by_permission', true);

        if (\is_null($permission)) {
            throw new InvalidDataException(
                'Terms must be acknowledged and agreed to',
            );
        }

        return ['granted_by_permission' => $permission];
    }

    /**
     * @return array<string, ?string>
     * @throws InvalidDataException
     * @throws DatetimeException
     */
    private function expire(): array
    {
        $value = $this->value('expire', true);

        if (\is_null($value)) {
            throw new InvalidDataException(
                'Expire time granted must be specified',
            );
        }

        $expire = date('Y-m-d', strtotime($value));

        if (
            $expire < date('Y-m-d') ||
            $expire > date('Y-m-d', strtotime('+1 year'))
        ) {
            throw new InvalidDataException(
                'Expire time granted should not exceed one year',
            );
        }

        return ['expire' => $expire];
    }

    /**
     * @param array<string, ?string> $post
     * @throws HttpInternalServerErrorException
     */
    private function send(array $post): void
    {
        \assert($this->settings['form'] instanceof FormConfig);

        if (
            !isset($post['granted_by_email']) ||
            !isset($post['granted_to_email']) ||
            \is_null($this->settings['form']->to)
        ) {
            throw new HttpInternalServerErrorException(
                $this->request,
                'Could not address mail',
            );
        }

        $cc = $this->settings['form']->cc;
        $cc[] = $post['granted_by_email'];
        $cc[] = $post['granted_to_email'];

        $message = $this->mail->message()
            ->from($post['granted_by_email'])
            ->to($this->settings['form']->to)
            ->cc(...$cc)
            ->subject($this->settings['form']->subject)
            ->htmlTemplate('mail.html.twig')
            ->context($post);

        try {
            $this->mail->send($message);
        } catch (FailedSendingException $exception) {
            throw new HttpInternalServerErrorException(
                $this->request,
                'Could not send mail',
                $exception,
            );
        }

        $this->message(
            'success',
            'Your ' . $this->settings['form']->subject .
            ' has been sent. You may send another request below.',
        );
    }
}
