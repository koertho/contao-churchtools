- Read and follow `AGENTS.local.md` in the repository root if it exists for additional local instructions.

## Coding standards
- Use Symfony PHP Translation Format for Contao and Other Translations where possible when creating new files.
- Use Doctrine Schema Representation for Contao DCA SQL Column Definition.
- Do not define a custom targetColumn for Contao DCA virtual fields unless explicitly requested.
- Use AbstractBundle class for the bundle class, if possible
- Register listeners with PHP attributes, never in configuration. Use `#[AsCallback]` for
  Contao DCA callbacks, `#[AsHook]` for Contao hooks, `#[AsEventListener]` for Symfony
  events, `#[AsCronJob]` for cronjobs, and the matching Contao attributes for content
  elements, frontend modules and insert tags. Do not add callbacks to DCA arrays
  (`'onload_callback' => [...]`), do not add hooks to `$GLOBALS['TL_HOOKS']`, and do not add
  `contao.callback` / `contao.hook` / `kernel.event_listener` tags to `services.yaml`.
  The attribute keeps the registration next to the code it registers.
- When several listeners share one callback target and their order matters: set an explicit
  `priority` on each attribute instead of relying on declaration order.
- Avoid trivial wrapper methods that only delegate to another method or wrap a simple expression without adding meaningful behavior. Keep such calls inline when the wrapper merely renames the underlying operation. Extract a method when it encapsulates meaningful logic, removes substantive duplication, or establishes a necessary extension or integration point. A descriptive name alone does not justify a one-line wrapper.
- Do not give your own concepts Contao-reserved names. For example the class suffix `*Model`and the directory `src/Model/` belong to Contao Active Record classes (`Contao\Model` subclasses registered in `$GLOBALS['TL_MODELS']`).
- Create Content Elements, not Frontend Module (Frontend Modules are a deprecated concept in contao)
- Do not create .html5 templates if not explicit required. Always create Twig templates in the contao managed namespace.

## Structure
- `contao/templates/`
    Store all templates here, also twig (not in symfony templates folder). If the extension has .html5 templates, use the `contao/templates/twig` folder for putting twig templates. The twig root folder must contain a `.twig-root` file. Twig-Templates within that folder can be addressed with the `@Contao` twig namespace. 
- `src/EventListener/Cron/`
    Cronjobs
- `src/EventListener/DataContainer/[Table]/`
    DCA Callback Listener. One Class per Callback. [Table ] is the table name without tl_ prefix and CamelCalse, for example Member for tl_member. Name the classes after the callback name with Listener suffix, for example ConfigOnLoadListener for 'config.onload' or FieldsExampleOptionsListener for 'fields.example.options'
- `src/Model/`
  Contao Active Record classes only. One class per table, named `<Table>Model`, extending `Contao\Model`, registered in `$GLOBALS['TL_MODELS']`.


