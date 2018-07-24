# SilverStripe Interactives Management module

A simple module to manage dynamic, interactive elements (including advertisements) on pages.

## Maintainer Contact

Marcus Nyeholt

<marcus (at) symbiote (dot) com (dot) au>

## Requirements

SilverStripe **4.x**

See the 1.x branch for SilverStripe 3 compatible code

## Documentation

Add

```
PageController:
  extensions:
    - Symbiote\Interactives\Extension\InteractiveControllerExtension
```

to your project's configuration yml file.

Note that interactives are inherited hierarchically, so setting ads on the Site Config
will mean those ads are used across all pages unless specified for a content
tree otherwise.


* Navigate to the "Interactives" section
* Create an Interactive Campaign
* Configure the campaign - "Use items as" refers to how items are displayed, based on
  * Always random - every time the campaign is displayed, one item is chosen randomly
  * Sticky random - the first time the campaign is displayed, one item is chosen randomly and always shown to that user on subsequent loads
  * All - all items are returned and displayed (think a multi-item ad list)
* For the campaign site options, you can choose to display on the whole site, specific pages, or choose the whole site and exclude specific URLs
* Once configured at the top level, add the interactive items themselves. 

### Interactives
  
A single interactive has a few options with how it is displayed. 

* Automatically generated link (Do not enter anything in the "HTMLContent" text area). If an image is attached to the interactive, this image is linked, otherwise the text in the Title field is linked
* Custom entered HTML (provide in the HTMLContent field)
* Binding to existing DOM nodes (only applicable if **Location in / near element** is set to "Existing content")

Target URLs for the interactive can be set as a fully qualified link, or an internal page object

* Relative Element is a jquery selector for inserting the interactive against; the "Location in" option provides the relative positioning to that element
* Display frequency allows for displaying to only a subset of users (ie to display to 20 percent of people, set this value to 5)
* The "Completion Element" is another jquery selector indicating the element on the target page that, if clicked, triggers a "complete" event for the given interactive. The usecase here being an interactive pointing to a userdefined form page, by setting this to the form submit button (eg _#UserForm_Form_action_process_) the submit of the form will be tracked
* If HTMLContent is filled out, this is used as the content of the interactive. 

