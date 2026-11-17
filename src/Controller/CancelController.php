<?php declare(strict_types=1);

namespace Shopproof\Compliance\Controller;

use Shopproof\Compliance\Cancel\CancelForm;
use Shopproof\Compliance\Cancel\CancelService;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Page\GenericPageLoaderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The cancel page (Germany § 312k BGB, France L. 215-1-1): the details, a summary with the final
 * button, then the confirmation with the time of receipt. Inside the shop's theme, no script,
 * never cached. While the setting is off the page takes nothing and names the shop's email.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => ['storefront']])]
final class CancelController extends StorefrontController
{
    public const ROUTE = 'frontend.shopproof.cancel';

    public function __construct(
        private readonly GenericPageLoaderInterface $pages,
        private readonly CancelForm $form,
        private readonly CancelService $cancel,
    ) {
    }

    #[Route(path: '/shopproof/cancel', name: self::ROUTE, methods: ['GET', 'POST'])]
    public function page(Request $request, SalesChannelContext $context): Response
    {
        $channel = $context->getSalesChannelId();
        $lang = $this->form->lang($context->getLanguageInfo()->localeCode);
        $today = gmdate('Y-m-d');
        $view = ['state' => 'form', 'lang' => $lang, 't' => $this->form->texts()[$lang] ?? [], 'data' => CancelForm::input([]), 'errors' => [], 'details' => [], 'body' => '', 'today' => $today];
        if (!$this->cancel->on($channel)) {
            $view['state'] = 'closed';
            $view['shopEmail'] = $this->cancel->shopEmail($channel);
        } elseif ($request->isMethod('POST')) {
            $view = $this->post($request, $view, $channel, $lang, $today);
        }
        $response = $this->renderStorefront('@ShopproofCompliance/storefront/page/shopproof/cancel.html.twig', [
            'page' => $this->pages->load($request, $context),
            'shopproofCancel' => $view,
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }

    /**
     * @param array<string, mixed> $view
     *
     * @return array<string, mixed>
     */
    private function post(Request $request, array $view, string $channel, string $lang, string $today): array
    {
        $step = $request->request->get(CancelForm::STEP);
        $data = CancelForm::input($request->request->all());
        $errors = CancelForm::errors($data, $today);
        $view['data'] = $data;
        if ($step === 'change' || $errors !== []) {
            $view['errors'] = $step === 'change' ? [] : $errors;

            return $view;
        }
        if ($step === 'review') {
            $view['state'] = 'summary';
            $view['details'] = $this->form->details($data, $lang);

            return $view;
        }
        if ($step !== 'send') {
            $view['data'] = CancelForm::input([]);

            return $view;
        }
        // A filled-in hidden field means a bot: it is told the cancellation arrived, nothing is kept.
        $honeypot = $request->request->get(CancelForm::HONEYPOT);
        if (\is_string($honeypot) && trim($honeypot) !== '') {
            $view['state'] = 'bot';

            return $view;
        }
        $record = $this->cancel->submit($data, $lang, $channel, (string) $request->getClientIp());
        if ($record === null) {
            $view['errors'] = ['_form' => 'failed'];

            return $view;
        }
        [$date, $time] = CancelService::when($record['receivedAt'], $lang);
        $values = ['date' => $date, 'time' => $time, 'email' => $record['email']];
        $view['state'] = 'done';
        $view['body'] = $this->form->text($record['receiptSent'] === '1' ? 'successBody' : 'successNoReceipt', $lang, $values);
        $view['details'] = $this->form->details($record, $lang);

        return $view;
    }
}
