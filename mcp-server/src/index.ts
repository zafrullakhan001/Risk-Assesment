#!/usr/bin/env node

/**
 * SharePoint Catalog MCP Server
 * 
 * Provides MCP tools for searching and querying SharePoint catalog data
 * from the Risk Register application.
 */

import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import {
  CallToolRequestSchema,
  ListToolsRequestSchema,
  Tool,
} from '@modelcontextprotocol/sdk/types.js';

interface Config {
  apiUrl: string;
  apiKey: string;
}

// Load configuration from environment variables
const config: Config = {
  apiUrl: process.env.SHAREPOINT_CATALOG_API_URL || 'http://localhost/api/mcp-catalog-search.php',
  apiKey: process.env.SHAREPOINT_CATALOG_API_KEY || '',
};

if (!config.apiKey) {
  console.error('Error: SHAREPOINT_CATALOG_API_KEY environment variable is required');
  process.exit(1);
}

/**
 * Make API request to the PHP backend
 */
async function apiRequest(action: string, params: Record<string, any> = {}): Promise<any> {
  const url = new URL(config.apiUrl);
  url.searchParams.append('action', action);
  
  // Add all params as query parameters
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== null) {
      url.searchParams.append(key, String(value));
    }
  }

  const response = await fetch(url.toString(), {
    method: 'GET',
    headers: {
      'X-API-Key': config.apiKey,
      'Accept': 'application/json',
    },
  });

  if (!response.ok) {
    const errorText = await response.text();
    throw new Error(`API request failed: ${response.status} ${response.statusText}\n${errorText}`);
  }

  const data = await response.json();
  
  if (!data.success) {
    throw new Error(data.error || 'API request failed');
  }

  return data;
}

/**
 * Define available MCP tools
 */
const tools: Tool[] = [
  {
    name: 'search_sharepoint_catalog',
    description: 'Search the SharePoint catalog for projects, files, and folders. Supports full-text search across project names, file names, paths, and people. Returns matching projects with their details.',
    inputSchema: {
      type: 'object',
      properties: {
        query: {
          type: 'string',
          description: 'Search query. Supports operators like tag:name, ext:pdf, person:"Last, First", "exact phrase", -exclude',
        },
        source: {
          type: 'string',
          description: 'Optional: specific catalog source key to search (e.g., "default", "public", "private"). If omitted, searches all sources.',
        },
        limit: {
          type: 'number',
          description: 'Maximum number of results to return (1-100, default: 25)',
          default: 25,
        },
      },
      required: ['query'],
    },
  },
  {
    name: 'list_sharepoint_projects',
    description: 'List SharePoint projects with pagination. Can optionally filter by search query. Returns project summaries including item counts, file counts, and last modified information.',
    inputSchema: {
      type: 'object',
      properties: {
        source: {
          type: 'string',
          description: 'Catalog source key (default: "default")',
          default: 'default',
        },
        query: {
          type: 'string',
          description: 'Optional search query to filter projects',
        },
        page: {
          type: 'number',
          description: 'Page number (default: 1)',
          default: 1,
        },
        per_page: {
          type: 'number',
          description: 'Results per page (1-100, default: 25)',
          default: 25,
        },
      },
    },
  },
  {
    name: 'get_sharepoint_project',
    description: 'Get detailed information about a specific SharePoint project, including all nested files and folders with their properties (size, modified date, URLs, etc.)',
    inputSchema: {
      type: 'object',
      properties: {
        project: {
          type: 'string',
          description: 'Project name (folder name)',
        },
        source: {
          type: 'string',
          description: 'Catalog source key (default: "default")',
          default: 'default',
        },
      },
      required: ['project'],
    },
  },
  {
    name: 'list_sharepoint_sources',
    description: 'List all available SharePoint catalog sources (e.g., Public, Private, different departments). Returns source information including sync status and item counts.',
    inputSchema: {
      type: 'object',
      properties: {},
    },
  },
  {
    name: 'get_sharepoint_source',
    description: 'Get detailed information about a specific SharePoint catalog source, including configuration and sync status.',
    inputSchema: {
      type: 'object',
      properties: {
        source: {
          type: 'string',
          description: 'Source key to retrieve',
        },
      },
      required: ['source'],
    },
  },
  {
    name: 'search_sharepoint_tags',
    description: 'Search for available tags that can be used to organize and filter SharePoint projects. Tags are custom labels assigned to projects.',
    inputSchema: {
      type: 'object',
      properties: {
        query: {
          type: 'string',
          description: 'Optional search query to filter tags by name',
        },
        limit: {
          type: 'number',
          description: 'Maximum number of tags to return (1-100, default: 50)',
          default: 50,
        },
      },
    },
  },
  {
    name: 'get_sharepoint_catalog_stats',
    description: 'Get statistics about all SharePoint catalog sources, including total items, project counts, and sync status. Useful for getting an overview of the catalog.',
    inputSchema: {
      type: 'object',
      properties: {},
    },
  },
];

/**
 * Handle tool execution
 */
async function handleToolCall(name: string, args: any): Promise<any> {
  switch (name) {
    case 'search_sharepoint_catalog': {
      const result = await apiRequest('search', {
        query: args.query,
        source: args.source,
        limit: args.limit || 25,
      });
      
      return {
        content: [
          {
            type: 'text',
            text: JSON.stringify(result, null, 2),
          },
        ],
      };
    }

    case 'list_sharepoint_projects': {
      const result = await apiRequest('list_projects', {
        source: args.source || 'default',
        query: args.query,
        page: args.page || 1,
        per_page: args.per_page || 25,
      });
      
      return {
        content: [
          {
            type: 'text',
            text: JSON.stringify(result, null, 2),
          },
        ],
      };
    }

    case 'get_sharepoint_project': {
      const result = await apiRequest('get_project', {
        project: args.project,
        source: args.source || 'default',
      });
      
      return {
        content: [
          {
            type: 'text',
            text: JSON.stringify(result, null, 2),
          },
        ],
      };
    }

    case 'list_sharepoint_sources': {
      const result = await apiRequest('list_sources');
      
      return {
        content: [
          {
            type: 'text',
            text: JSON.stringify(result, null, 2),
          },
        ],
      };
    }

    case 'get_sharepoint_source': {
      const result = await apiRequest('get_source', {
        source: args.source,
      });
      
      return {
        content: [
          {
            type: 'text',
            text: JSON.stringify(result, null, 2),
          },
        ],
      };
    }

    case 'search_sharepoint_tags': {
      const result = await apiRequest('search_tags', {
        query: args.query,
        limit: args.limit || 50,
      });
      
      return {
        content: [
          {
            type: 'text',
            text: JSON.stringify(result, null, 2),
          },
        ],
      };
    }

    case 'get_sharepoint_catalog_stats': {
      const result = await apiRequest('stats');
      
      return {
        content: [
          {
            type: 'text',
            text: JSON.stringify(result, null, 2),
          },
        ],
      };
    }

    default:
      throw new Error(`Unknown tool: ${name}`);
  }
}

/**
 * Main server setup
 */
async function main() {
  const server = new Server(
    {
      name: 'sharepoint-catalog-server',
      version: '1.0.0',
    },
    {
      capabilities: {
        tools: {},
      },
    }
  );

  // List available tools
  server.setRequestHandler(ListToolsRequestSchema, async () => {
    return { tools };
  });

  // Handle tool calls
  server.setRequestHandler(CallToolRequestSchema, async (request) => {
    try {
      const { name, arguments: args } = request.params;
      return await handleToolCall(name, args || {});
    } catch (error) {
      const errorMessage = error instanceof Error ? error.message : String(error);
      return {
        content: [
          {
            type: 'text',
            text: `Error: ${errorMessage}`,
          },
        ],
        isError: true,
      };
    }
  });

  // Start the server
  const transport = new StdioServerTransport();
  await server.connect(transport);
  
  console.error('SharePoint Catalog MCP server running on stdio');
}

main().catch((error) => {
  console.error('Fatal error:', error);
  process.exit(1);
});
