<?php
namespace Wwwision\PrivateResources\Http\Component;

/*                                                                             *
 * This script belongs to the TYPO3 Flow package "Wwwision.PrivateResources".  *
 *                                                                             */

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Exception as FlowException;
use TYPO3\Flow\Http\Component\ComponentChain;
use TYPO3\Flow\Http\Component\ComponentContext;
use TYPO3\Flow\Http\Component\ComponentInterface;
use TYPO3\Flow\Http\Request as HttpRequest;
use TYPO3\Flow\Mvc\ActionRequest;
use TYPO3\Flow\Object\DependencyInjection\DependencyProxy;
use TYPO3\Flow\Object\ObjectManagerInterface;
use TYPO3\Flow\Resource\Resource;
use TYPO3\Flow\Resource\ResourceManager;
use TYPO3\Flow\Security\Context;
use TYPO3\Flow\Security\Cryptography\HashService;
use TYPO3\Flow\Security\Exception\AccessDeniedException;
use TYPO3\Flow\Security\Exception\InvalidHashException;
use TYPO3\Flow\Security\Policy\Role;
use TYPO3\Flow\Utility\Files;
use TYPO3\Flow\Utility\Now;
use Wwwision\PrivateResources\Http\Component\Exception\FileNotFoundException;
use Wwwision\PrivateResources\Http\FileServeStrategy\FileServeStrategyInterface;

/**
 * A HTTP Component that checks for the request argument "__protectedResource" and outputs the requested resource if the client tokens match
 */
class ProtectedResourceComponent implements ComponentInterface
{

    /**
     * @Flow\Inject
     * @var HashService
     */
    protected $hashService;

    /**
     * @Flow\Inject
     * @var ResourceManager
     */
    protected $resourceManager;

    /**
     * @Flow\Inject
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @Flow\Inject
     * @var Context
     */
    protected $securityContext;

    /**
     * @Flow\Inject
     * @var Now
     */
    protected $now;

    /**
     * @var array
     */
    protected $options;

    /**
     * @param array $options
     */
    public function __construct(array $options = [])
    {
        $this->options = $options;
    }

    /**
     * @param ComponentContext $componentContext
     * @return void
     * @throws FileNotFoundException|AccessDeniedException|FlowException
     */
    public function handle(ComponentContext $componentContext)
    {
        /** @var HttpRequest $httpRequest */
        $httpRequest = $componentContext->getHttpRequest();
        if (!$httpRequest->hasArgument('__protectedResource')) {
            return;
        }
        try {
            $encodedResourceData = $this->hashService->validateAndStripHmac($httpRequest->getArgument('__protectedResource'));
        } catch (InvalidHashException $exception) {
            throw new AccessDeniedException('Invalid HMAC!', 1421241393, $exception);
        }
        $tokenData = json_decode(base64_decode($encodedResourceData), true);

        $this->verifyExpiration($tokenData, $httpRequest);
        $this->verifySecurityContext($tokenData, $httpRequest);

        $resource = $this->resourceManager->getResourceBySha1($tokenData['resourceIdentifier']);
        if ($resource === null) {
            throw new FileNotFoundException(sprintf('Unknown resource!%sCould not find resource with identifier "%s"',
                chr(10), $tokenData['resourceIdentifier']), 1429621743);
        }

        // TODO there should be a better way to determine the absolute path of the resource? Resource::createTemporaryLocalCopy() is too expensive
        $resourcePathAndFilename = Files::concatenatePaths([
            $this->options['basePath'],
            $tokenData['resourceIdentifier'][0],
            $tokenData['resourceIdentifier'][1],
            $tokenData['resourceIdentifier'][2],
            $tokenData['resourceIdentifier'][3],
            $tokenData['resourceIdentifier']
        ]);
        if (!is_file($resourcePathAndFilename)) {
            throw new FileNotFoundException(sprintf('File not found!%sThe file "%s" does not exist', chr(10),
                $resourcePathAndFilename), 1429702284);
        }

        if (!isset($this->options['serveStrategy'])) {
            throw new FlowException('No "serveStrategy" configured!', 1429704107);
        }
        $fileServeStrategy = $this->objectManager->get($this->options['serveStrategy']);
        if (!$fileServeStrategy instanceof FileServeStrategyInterface) {
            throw new FlowException(sprintf('The class "%s" does not implement the FileServeStrategyInterface',
                get_class($fileServeStrategy)), 1429704284);
        }
        $httpResponse = $componentContext->getHttpResponse();
        $httpResponse->setHeader('Content-Type', $resource->getMediaType());
        $httpResponse->setHeader('Content-Disposition', 'attachment;filename="' . $resource->getFilename() . '"');
        $httpResponse->setHeader('Content-Length', $resource->getFileSize());

        $this->emitResourceServed($resource, $httpRequest);

        $fileServeStrategy->serve($resourcePathAndFilename, $httpResponse);
        $componentContext->setParameter(ComponentChain::class, 'cancel', true);
    }

    /**
     * Checks whether the token is expired
     *
     * @param array $tokenData
     * @param HttpRequest $httpRequest
     * @return void
     * @throws AccessDeniedException
     */
    protected function verifyExpiration(array $tokenData, HttpRequest $httpRequest)
    {
        if (!isset($tokenData['expirationDateTime'])) {
            return;
        }
        $expirationDateTime = \DateTime::createFromFormat(\DateTime::ATOM, $tokenData['expirationDateTime']);
        if ($this->now instanceof DependencyProxy) {
            $this->now->_activateDependency();
        }
        if ($expirationDateTime < $this->now) {
            $this->emitAccessDenied($tokenData, $httpRequest);
            throw new AccessDeniedException(sprintf('Token expired!%sThis token expired at "%s"', chr(10), $expirationDateTime->format(\DateTime::ATOM)), 1429697439);
        }
    }

    /**
     * Checks whether the currently authenticated user is allowed to access the resource
     *
     * @param array $tokenData
     * @param HttpRequest $httpRequest
     * @return void
     * @throws AccessDeniedException
     */
    protected function verifySecurityContext(array $tokenData, HttpRequest $httpRequest)
    {
        if (!isset($tokenData['securityContextHash']) && !isset($tokenData['privilegedRole'])) {
            return;
        }
        $actionRequest = new ActionRequest($httpRequest);
        $this->securityContext->setRequest($actionRequest);
        if (isset($tokenData['privilegedRole'])) {
            if ($this->securityContext->hasRole($tokenData['privilegedRole'])) {
                return;
            }
            $authenticatedRoleIdentifiers = array_map(static function(Role $role) { return $role->getIdentifier(); }, $this->securityContext->getRoles());
            $this->emitAccessDenied($tokenData, $httpRequest);
            throw new AccessDeniedException(sprintf('Access denied!%sThis request is signed for a role "%s", but only the following roles are authenticated: %s', chr(10), $tokenData['privilegedRole'], implode(', ', $authenticatedRoleIdentifiers)), 1565856716);
        }

        if ($tokenData['securityContextHash'] !== $this->securityContext->getContextHash()) {
            $this->emitAccessDenied($tokenData, $httpRequest);
            $this->emitInvalidSecurityContextHash($tokenData, $httpRequest);
            throw new AccessDeniedException(sprintf('Invalid security hash!%sThis request is signed for a security context hash of "%s", but the current hash is "%s"', chr(10), $tokenData['securityContextHash'], $this->securityContext->getContextHash()), 1429705633);
        }
    }

    /**
     * Signals that all persistAll() has been executed successfully.
     *
     * @Flow\Signal
     * @param Resource $resource the resource that has been served
     * @param HttpRequest $httpRequest the current HTTP request
     * @return void
     */
    protected function emitResourceServed(Resource $resource, HttpRequest $httpRequest)
    {
    }

    /**
     * Signals that the token verification failed
     *
     * @Flow\Signal
     * @param array $tokenData the token data
     * @param HttpRequest $httpRequest the current HTTP request
     * @return void
     */
    protected function emitAccessDenied(array $tokenData, HttpRequest $httpRequest)
    {
    }

    /**
     * Signals that the security context hash verification failed
     *
     * @Flow\Signal
     * @deprecated use "accessDenied" signal instead
     * @param array $tokenData the token data
     * @param HttpRequest $httpRequest the current HTTP request
     * @return void
     */
    protected function emitInvalidSecurityContextHash(array $tokenData, HttpRequest $httpRequest)
    {
    }
}
