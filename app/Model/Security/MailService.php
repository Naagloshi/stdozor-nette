<?php

declare(strict_types=1);

namespace App\Model\Security;

use Nette\Bridges\ApplicationLatte\LatteFactory;
use Nette\Mail\Mailer;
use Nette\Mail\Message;

final class MailService
{
	public function __construct(
		private Mailer $mailer,
		private LatteFactory $latteFactory,
	) {}

	/**
	 * Send an HTML email rendered from a Latte template.
	 *
	 * @param array<string, mixed> $params
	 */
	public function send(string $to, string $subject, string $templateFile, array $params = []): void
	{
		$latte = $this->latteFactory->create();
		$html = $latte->renderToString($templateFile, $params);

		$mail = new Message();
		$mail->setFrom('noreply@stdozor.local', 'Stavební dozor');
		$mail->addTo($to);
		$mail->setSubject($subject);
		$mail->setHtmlBody($html);

		$this->mailer->send($mail);
	}
}
