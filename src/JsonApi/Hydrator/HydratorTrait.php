<?php
namespace WoohooLabs\Yin\JsonApi\Hydrator;

use WoohooLabs\Yin\JsonApi\Exception\ExceptionFactoryInterface;
use WoohooLabs\Yin\JsonApi\Hydrator\Relationship\ToManyRelationship;
use WoohooLabs\Yin\JsonApi\Hydrator\Relationship\ToOneRelationship;
use WoohooLabs\Yin\JsonApi\Schema\ResourceIdentifier;

trait HydratorTrait
{
    /**
     * Determines which resource type or types can be accepted by the hydrator.
     *
     * If the hydrator can only accept one type of resources, the method should
     * return a string. If it accepts more types, then it should return an array
     * of strings. When such a resource is received for hydration which can't be
     * accepted (its type doesn't match the acceptable type or types of the hydrator),
     * a ResourceTypeUnacceptable exception will be raised.
     *
     * @return string|array
     */
    abstract protected function getAcceptedType();

    /**
     * Provides the attribute hydrators.
     *
     * The method returns an array of attribute hydrators, where a hydrator is a key-value pair:
     * the key is the specific attribute name which comes from the request and the value is a
     * callable which hydrates the given attribute.
     * These callables receive the domain object (which will be hydrated), the value of the
     * currently processed attribute, the "data" part of the request and the name of the attribute
     * to be hydrated as their arguments, and they should mutate the state of the domain object.
     * If it is an immutable object or an array (and passing by reference isn't used),
     * the callable should return the domain object.
     *
     * @param mixed $domainObject
     * @return callable[]
     */
    abstract protected function getAttributeHydrator($domainObject);

    /**
     * Provides the relationship hydrators.
     *
     * The method returns an array of relationship hydrators, where a hydrator is a key-value pair:
     * the key is the specific relationship name which comes from the request and the value is an
     * callable which hydrate the previous relationship.
     * These callables receive the domain object (which will be hydrated), an object representing the
     * currently processed relationship (it can be a ToOneRelationship or a ToManyRelationship
     * object), the "data" part of the request and the relationship name as their arguments, and
     * they should mutate the state of the domain object.
     * If it is an immutable object or an array (and passing by reference isn't used),
     * the callable should return the domain object.
     *
     * @param mixed $domainObject
     * @return callable[]
     */
    abstract protected function getRelationshipHydrator($domainObject);

    /**
     * @param array $data
     * @param \WoohooLabs\Yin\JsonApi\Exception\ExceptionFactoryInterface $exceptionFactory
     * @throws \Exception
     */
    protected function validateType($data, ExceptionFactoryInterface $exceptionFactory)
    {
        if (empty($data["type"])) {
            throw $exceptionFactory->createResourceTypeMissingException();
        }

        $acceptedType = $this->getAcceptedType();

        if (is_string($acceptedType) === true && $data["type"] !== $acceptedType) {
            throw $exceptionFactory->createResourceTypeUnacceptableException($data["type"], [$acceptedType]);
        }

        if (is_array($acceptedType) && in_array($data["type"], $acceptedType) === false) {
            throw $exceptionFactory->createResourceTypeUnacceptableException($data["type"], $acceptedType);
        }
    }

    /**
     * @param mixed $domainObject
     * @param array $data
     * @return mixed
     */
    protected function hydrateAttributes($domainObject, array $data)
    {
        if (empty($data["attributes"])) {
            return $domainObject;
        }

        $attributeHydrator = $this->getAttributeHydrator($domainObject);
        foreach ($attributeHydrator as $attribute => $hydrator) {

            $result = $hydrator($domainObject, $data["attributes"][$attribute] ?? null, $data, $attribute, !array_key_exists($attribute, $data["attributes"]));
            if ($result) {
                $domainObject = $result;
            }
        }

        return $domainObject;
    }

    /**
     * @param mixed $domainObject
     * @param array $data
     * @param \WoohooLabs\Yin\JsonApi\Exception\ExceptionFactoryInterface $exceptionFactory
     * @return mixed
     */
    protected function hydrateRelationships($domainObject, array $data, ExceptionFactoryInterface $exceptionFactory)
    {
        if (empty($data["relationships"])) {
            return $domainObject;
        }

        $relationshipHydrator = $this->getRelationshipHydrator($domainObject);
        foreach ($relationshipHydrator as $relationship => $hydrator) {
            if (isset($data["relationships"][$relationship]) === false) {
                continue;
            }

            $domainObject = $this->doHydrateRelationship(
                $domainObject,
                $relationship,
                $hydrator,
                $exceptionFactory,
                $data["relationships"][$relationship],
                $data
            );
        }

        return $domainObject;
    }

    /**
     * @param mixed $domainObject
     * @param string $relationshipName
     * @param callable $hydrator
     * @param ExceptionFactoryInterface $exceptionFactory
     * @param array|null $relationshipData
     * @param array|null $data
     * @return mixed
     */
    protected function doHydrateRelationship(
        $domainObject,
        $relationshipName,
        callable $hydrator,
        ExceptionFactoryInterface $exceptionFactory,
        $relationshipData,
        $data
    ) {
        $relationshipObject = $this->createRelationship(
            $relationshipData,
            $exceptionFactory
        );

        if ($relationshipObject !== null) {
            $result = $this->getRelationshipHydratorResult(
                $relationshipName,
                $hydrator,
                $domainObject,
                $relationshipObject,
                $data,
                $exceptionFactory
            );

            if ($result) {
                $domainObject = $result;
            }
        }

        return $domainObject;
    }

    /**
     * @param string $relationshipName
     * @param callable $hydrator
     * @param mixed $domainObject
     * @param ToOneRelationship|ToManyRelationship $relationshipObject
     * @param array|null $data
     * @param \WoohooLabs\Yin\JsonApi\Exception\ExceptionFactoryInterface $exceptionFactory
     * @return mixed
     * @throws \WoohooLabs\Yin\JsonApi\Exception\RelationshipTypeInappropriate
     * @throws \Exception
     */
    protected function getRelationshipHydratorResult(
        $relationshipName,
        callable $hydrator,
        $domainObject,
        $relationshipObject,
        $data,
        ExceptionFactoryInterface $exceptionFactory
    ) {
        // Checking if the current and expected relationship types match
        $relationshipType = $this->getRelationshipType($relationshipObject);
        $expectedRelationshipType = $this->getRelationshipType($this->getArgumentTypeHintFromCallable($hydrator));
        if ($expectedRelationshipType !== null && $relationshipType !== $expectedRelationshipType) {
            throw $exceptionFactory->createRelationshipTypeInappropriateException(
                $relationshipName,
                $relationshipType,
                $expectedRelationshipType
            );
        }

        // Returning if the hydrator returns the hydrated domain object
        $value = $hydrator($domainObject, $relationshipObject, $data, $relationshipName);
        if ($value) {
            return $value;
        }

        // Returning the domain object which was mutated but not returned by the hydrator
        return $domainObject;
    }

    /**
     * @param callable $callable
     * @return string|null
     */
    protected function getArgumentTypeHintFromCallable(callable $callable)
    {
        $function = &$callable;
        $reflection = new \ReflectionFunction($function);
        $arguments  = $reflection->getParameters();

        if (empty($arguments) === false && isset($arguments[1]) && $arguments[1]->getClass()) {
            return $arguments[1]->getClass()->getName();
        }

        return null;
    }

    /**
     * @param object|string|null $object
     * @return string|null
     */
    protected function getRelationshipType($object)
    {
        if ($object instanceof ToOneRelationship || $object === ToOneRelationship::class) {
            return "to-one";
        } elseif ($object instanceof ToManyRelationship || $object === ToManyRelationship::class) {
            return "to-many";
        }

        return null;
    }

    /**
     * @param array|null $relationship
     * @param ExceptionFactoryInterface $exceptionFactory
     * @return \WoohooLabs\Yin\JsonApi\Hydrator\Relationship\ToOneRelationship|
     * \WoohooLabs\Yin\JsonApi\Hydrator\Relationship\ToManyRelationship|null
     */
    private function createRelationship($relationship, ExceptionFactoryInterface $exceptionFactory)
    {
        if (array_key_exists("data", $relationship) === false) {
            return null;
        }

        //If this is a request to clear the relationship, we create an empty relationship
        if (is_null($relationship["data"])) {
            $result = new ToOneRelationship();
        } elseif ($this->isAssociativeArray($relationship["data"]) === true) {
            $result = new ToOneRelationship(
                ResourceIdentifier::fromArray($relationship["data"], $exceptionFactory)
            );
        } else {
            $result = new ToManyRelationship();
            foreach ($relationship["data"] as $relationship) {
                $result->addResourceIdentifier(
                    ResourceIdentifier::fromArray($relationship, $exceptionFactory)
                );
            }
        }

        return $result;
    }

    /**
     * @param array $array
     * @return bool
     */
    private function isAssociativeArray(array $array)
    {
        return (bool)count(array_filter(array_keys($array), 'is_string'));
    }
}
