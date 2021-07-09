# Performance helpers for Neos CMS
                                                             
This is package under development that will provide several methods to improve 
performance of page rendering in Neos CMS.

I try to get most optimizations into the Neos core itself, but some
first need to be tested and improved and therefore live in this package.

## Node preloader

A Fusion object attached to the main Page prototype.
It will preload all content nodes at the beginning of the rendering
with a few optimized queries. This way a lot of queries will be skipped
during the rendering of content elements.
Content references are also being followed if they reference 
`ContentCollections`.
                                          
In the `Neos.Demo` this will f.e. remove ~20% of queries.

In a larger project several hundred queries could be skipped. 
