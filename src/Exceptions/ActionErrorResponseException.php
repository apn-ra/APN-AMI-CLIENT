<?php

declare(strict_types=1);

namespace Apn\AmiClient\Exceptions;

final class ActionErrorResponseException extends ProtocolException
{
    public function __construct(
        private readonly ?string $actionId,
        private readonly ?string $amiMessage,
        private readonly ?string $responseType = 'Error',
    ) {
        $message = sprintf(
            'AMI action failed%s%s',
            $actionId !== null ? ' [ActionID: ' . $actionId . ']' : '',
            $amiMessage !== null ? ': ' . $amiMessage : ''
        );

        parent::__construct($message);
    }

    public function getActionId(): ?string
    {
        return $this->actionId;
    }

    public function getAmiMessage(): ?string
    {
        return $this->amiMessage;
    }

    public function getResponseType(): ?string
    {
        return $this->responseType;
    }
}
