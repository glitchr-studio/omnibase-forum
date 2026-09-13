<?php

namespace Base\Forum\Entity;

use Base\Database\Attribute\Slugify;
use Base\Database\Attribute\Timestamp;
use Base\Forum\Repository\CategoryRepository;
use Base\Service\Model\IconizeInterface;
use Base\Service\Model\LinkableInterface;
use Base\Traits\BaseTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A board of the forum: "Agora", "Helpz", "Games"...
 *
 * Categories form a two-level tree, the way phpBB's categories held forums:
 * a root category is a group (the old `phpbb_categories`), its children are
 * the boards topics are posted in (the old `phpbb_forums`). Topics may only
 * be posted in a leaf.
 */
#[ORM\Entity(repositoryClass: CategoryRepository::class)]
#[ORM\Table(name: 'forum_category')]
class Category implements LinkableInterface, IconizeInterface
{
    use BaseTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    protected $id;

    #[ORM\Column(type: 'string', length: 128)]
    #[Assert\NotBlank(groups: ['new', 'edit'])]
    #[Assert\Length(max: 128, groups: ['new', 'edit'])]
    protected ?string $title = null;

    #[ORM\Column(type: 'string', length: 128, unique: true)]
    #[Slugify(reference: 'title')]
    protected ?string $slug = null;

    #[ORM\Column(type: 'text', nullable: true)]
    protected ?string $description = null;

    /** A FontAwesome class ("fa-solid fa-comments") - no uploads needed for a board icon. */
    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    protected ?string $icon = null;

    /** Accent colour of the board, "#6090BE"-style. */
    #[ORM\Column(type: 'string', length: 9, nullable: true)]
    protected ?string $color = null;

    #[ORM\Column(type: 'integer')]
    protected int $position = 0;

    /** A locked board keeps its topics readable but takes no new one (the old "Messages Officiels"). */
    #[ORM\Column(type: 'boolean')]
    protected bool $locked = false;

    /** Role required to READ the board; null means everyone. The old "BBS Modos"/"BBS Créateurs". */
    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    protected ?string $requiredRole = null;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    protected ?Category $parent = null;

    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent', cascade: ['persist'])]
    #[ORM\OrderBy(['position' => 'ASC', 'title' => 'ASC'])]
    protected Collection $children;

    #[ORM\OneToMany(targetEntity: Topic::class, mappedBy: 'category')]
    protected Collection $topics;

    #[ORM\Column(type: 'datetime')]
    #[Timestamp(on: 'create')]
    protected ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: 'datetime')]
    #[Timestamp(on: ['create', 'update'])]
    protected ?\DateTimeInterface $updatedAt = null;

    public function __construct(?string $title = null, ?Category $parent = null)
    {
        $this->title = $title;
        $this->parent = $parent;
        $this->children = new ArrayCollection();
        $this->topics = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->title ?? '';
    }

    public function __iconize(): ?array
    {
        return [$this->icon ?? 'fa-solid fa-comments'];
    }

    public static function __iconizeStatic(): ?array
    {
        return ['fa-solid fa-comments'];
    }

    public function __toLink(array $routeParameters = [], int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH): ?string
    {
        return $this->getRouter()->generate('forum_category', array_merge($routeParameters, ['slug' => $this->slug]), $referenceType);
    }

    public function getId(): ?int { return $this->id; }

    public function getTitle(): ?string { return $this->title; }
    public function setTitle(?string $title): self { $this->title = $title; return $this; }

    public function getSlug(): ?string { return $this->slug; }
    public function setSlug(?string $slug): self { $this->slug = $slug; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $this->description = $description; return $this; }

    public function getIcon(): ?string { return $this->icon; }
    public function setIcon(?string $icon): self { $this->icon = $icon; return $this; }

    public function getColor(): ?string { return $this->color; }
    public function setColor(?string $color): self { $this->color = $color; return $this; }

    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): self { $this->position = $position; return $this; }

    public function isLocked(): bool { return $this->locked; }
    public function setLocked(bool $locked): self { $this->locked = $locked; return $this; }

    public function getRequiredRole(): ?string { return $this->requiredRole; }
    public function setRequiredRole(?string $requiredRole): self { $this->requiredRole = $requiredRole ?: null; return $this; }

    public function getParent(): ?Category { return $this->parent; }
    public function setParent(?Category $parent): self { $this->parent = $parent; return $this; }

    /** @return Collection<int, Category> */
    public function getChildren(): Collection { return $this->children; }
    public function addChild(Category $child): self
    {
        if (!$this->children->contains($child)) {
            $this->children[] = $child;
            $child->setParent($this);
        }
        return $this;
    }

    /** A board (leaf) takes topics; a group only holds boards. */
    public function isBoard(): bool { return null !== $this->parent; }
    public function isGroup(): bool { return null === $this->parent; }

    /** @return Collection<int, Topic> */
    public function getTopics(): Collection { return $this->topics; }

    public function getCreatedAt(): ?\DateTimeInterface { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeInterface { return $this->updatedAt; }
}
