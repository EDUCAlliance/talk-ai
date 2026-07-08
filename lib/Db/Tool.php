<?php

declare(strict_types=1);

namespace OCA\EducAI\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method string getName()
 * @method void setName(string $name)
 * @method ?string getDescription()
 * @method void setDescription(?string $description)
 * @method string getMcpEndpointUrl()
 * @method void setMcpEndpointUrl(string $mcpEndpointUrl)
 * @method ?string getAuthentication()
 * @method void setAuthentication(?string $authentication)
 * @method ?string getCapabilities()
 * @method void setCapabilities(?string $capabilities)
 * @method bool getEnabled()
 * @method void setEnabled(bool $enabled)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class Tool extends Entity implements JsonSerializable {
    protected string $name = '';
    protected ?string $description = null;
    protected string $mcpEndpointUrl = '';
    protected ?string $authentication = null;
    protected ?string $capabilities = null;
    protected bool $enabled = false;
    protected int $createdAt = 0;
    protected int $updatedAt = 0;

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'mcp_endpoint_url' => $this->mcpEndpointUrl,
            'authentication' => $this->authentication !== null ? '***' : null,
            'capabilities' => $this->capabilities !== null ? json_decode($this->capabilities, true) : null,
            'enabled' => $this->enabled,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
