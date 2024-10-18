<?php
namespace ON\View;

use On\Config\Config;

class Node {
    protected array $values = [];

    public function __construct(
        protected mixed $parent
    )
    {

    }
    
    public function set(array $values): self
    {
        $this->values = $values;
        return $this;
    }

    public function get(string $name = null): array
    {
        if ($name === null) {
            return $this->values;
        }
        if (isset($this->values[$name])) {
            return $this->values[$name];
        }
        return null;
    }

    public function serialize(): mixed
    {
        return $this->values;
    }
}

class CollectionNode extends Node {
    public function end() {
        return $this->parent;
    }
}

class RendererNode extends CollectionNode {
    protected array $injects = [];
    public function inject(string $name, string $class): self
    {
        $this->injects[$name] = $class;
        return $this;
    } 
    public function end(): FormatNode
    {
        return $this->parent;
    }
    public function serialize(): mixed
    {
        $values = $this->values;
        $values["inject"] = $this->injects;
        return $values;
    }
}

class TemplateNode extends CollectionNode {
    public function end(): ViewConfig
    {
        return $this->parent;
    }
}

class FormatNode extends CollectionNode {
    protected array $layouts = [];
    protected array $renderers = [];

    public function layout(string $name, $values = null): LayoutNode
    {   $layout = new LayoutNode($this);
        $layout->set($values);
        $this->layouts[$name] = $layout;
        return $layout;
    }

    public function renderer(string $name, $class = null): RendererNode
    {   $renderer = new RendererNode($this);
        $renderer->set(["class" => $class]);
        $this->renderers[$name] = $renderer;
        return $renderer;
    }

    public function serialize(): mixed
    {
        $values = $this->get();
        $values["layouts"] = [];
        foreach ($this->layouts as $key => $layout) {
            $values["layouts"][$key] = $layout->serialize();
        }

        $values["renderers"] = [];
        foreach ($this->renderers as $key => $renderer) {
            $values["renderers"][$key] = $renderer->serialize();
        }
        return $values;
    }
}

class SectionNode extends Node {
    public function serialize(): array
    {
        return $this->values;
    }
}



class LayoutNode extends CollectionNode {
    protected array $sections = [];

    public function section(string $name, $path, $controller, $methods, $route_name): LayoutNode
    {
        $this->sections[$name] = new SectionNode($this);
    
        $this->sections[$name]->set([
            $path,
            $controller,
            $methods,
            $route_name
        ]);

        return $this;
    }

    public function get(string $name = null): array
    {
        $values = $this->values;
        $values["sections"] = [];
        foreach ($this->sections as $name => $section) {
            $values["sections"][$name] = $section->get();
        }
        return $values;
    }
    public function end(): FormatNode
    {
        return $this->parent;
    }
}




class ViewConfig extends Config {

    protected array $formats = [];

    public function format(string $name): FormatNode
    {
        $this->formats[$name] = new FormatNode($this);
        return $this->formats[$name];
    }

    public function done(): mixed
    {
        $formats = [];
        foreach ($this->formats as $name => $format) {
            $formats[$name] = $format->serialize();
        }
        $values = [
            "formats" => $formats
        ];
        $this->set($values);
        return $values;
    }

}